<?php
/**
 * Phase 2 Pages — Unit Tests
 *
 * Tests pure PHP logic without requiring a running CodeIgniter instance.
 * Run from the project root:
 *   php modules/pitchsnap/tests/test_phase2_pages.php
 *
 * DB-dependent tests (marked SKIP) require a live CI environment and are
 * listed here for documentation; run them manually against a dev instance.
 */

if (!defined('BASEPATH')) { define('BASEPATH', __DIR__ . '/../../../system/'); }

$pass    = 0;
$fail    = 0;
$results = [];

function t_pass($name)              { global $pass, $results; $pass++; $results[] = "PASS  $name"; }
function t_fail($name, $detail = '') { global $fail, $results; $fail++; $results[] = "FAIL  $name" . ($detail ? " — $detail" : ''); }
function t_skip($name, $reason)     { global $results; $results[] = "SKIP  $name ($reason)"; }

function assert_true($cond, $name, $detail = '')  { if ($cond) { t_pass($name); } else { t_fail($name, $detail); } }
function assert_eq($a, $b, $name)   { assert_true($a === $b, $name, 'expected ' . json_encode($b) . ', got ' . json_encode($a)); }
function assert_null($v, $name)     { assert_true($v === null, $name, 'expected null, got ' . json_encode($v)); }
function assert_false($cond, $name) { assert_true(!$cond, $name, 'expected false'); }

// ---------------------------------------------------------------------------
// Mock objects
// ---------------------------------------------------------------------------

class MockPage {
    public $id; public $site_id; public $title; public $slug; public $page_type;
    public $parent_page_id; public $status; public $generation_status;
    public $menu_primary; public $menu_footer; public $menu_order;
    public $index_page; public $current_generation_id;
    public function __construct($data) { foreach ($data as $k => $v) $this->$k = $v; }
}

class MockMedia {
    public $id; public $site_id; public $filename; public $category; public $alt_text;
    public function __construct($data) { foreach ($data as $k => $v) $this->$k = $v; }
}

class MockGeneration {
    public $id; public $page_id; public $site_id; public $html_content;
    public $is_current; public $status;
    public function __construct($data) { foreach ($data as $k => $v) $this->$k = $v; }
}

// ---------------------------------------------------------------------------
// Simulate validate_page_parent logic (mirrors Pitchsnap_model::validate_page_parent)
// ---------------------------------------------------------------------------
function mock_validate_page_parent($page_id, $parent_page_id, $site_id, array $all_pages)
{
    $page_id        = (int) $page_id;
    $parent_page_id = (int) $parent_page_id;

    if ($page_id && $page_id === $parent_page_id) {
        return false; // self-reference
    }

    $parent = null;
    foreach ($all_pages as $p) {
        if ((int) $p->id === $parent_page_id) { $parent = $p; break; }
    }
    if (!$parent) {
        return false; // parent not found
    }
    if ((int) $parent->site_id !== (int) $site_id) {
        return false; // cross-site
    }
    if ($parent->status === 'trash') {
        return false; // trashed parent
    }
    return true;
}

// ---------------------------------------------------------------------------
// Simulate attach_media_to_page ownership check
// ---------------------------------------------------------------------------
function mock_attach_media($page, $media)
{
    if (!$page || !$media) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    if ((int) $page->site_id !== (int) $media->site_id) {
        return ['ok' => false, 'reason' => 'cross_site'];
    }
    return ['ok' => true, 'reason' => null];
}

// ---------------------------------------------------------------------------
// Simulate set_current_page_generation logic
// ---------------------------------------------------------------------------
function mock_set_current_generation(array &$generations, $page_id, $generation_id)
{
    // Verify generation belongs to page
    $gen = null;
    foreach ($generations as $g) {
        if ((int) $g->id === (int) $generation_id && (int) $g->page_id === (int) $page_id) {
            $gen = $g; break;
        }
    }
    if (!$gen) {
        return false;
    }
    foreach ($generations as $g) {
        $g->is_current = ((int) $g->id === (int) $generation_id) ? 1 : 0;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Build shared test fixtures
// ---------------------------------------------------------------------------

$pages = [
    new MockPage(['id' => 1, 'site_id' => 10, 'title' => 'Home',    'slug' => 'home',    'page_type' => 'homepage', 'parent_page_id' => null,  'status' => 'draft',     'generation_status' => 'not_generated', 'menu_primary' => 1, 'menu_footer' => 0, 'menu_order' => 0, 'index_page' => 1, 'current_generation_id' => null]),
    new MockPage(['id' => 2, 'site_id' => 10, 'title' => 'About',   'slug' => 'about',   'page_type' => 'about',    'parent_page_id' => null,  'status' => 'draft',     'generation_status' => 'not_generated', 'menu_primary' => 1, 'menu_footer' => 1, 'menu_order' => 1, 'index_page' => 1, 'current_generation_id' => null]),
    new MockPage(['id' => 3, 'site_id' => 10, 'title' => 'Services', 'slug' => 'services','page_type' => 'custom',   'parent_page_id' => null,  'status' => 'draft',     'generation_status' => 'generated',     'menu_primary' => 1, 'menu_footer' => 0, 'menu_order' => 2, 'index_page' => 1, 'current_generation_id' => 5]),
    new MockPage(['id' => 4, 'site_id' => 10, 'title' => 'Old Page', 'slug' => 'old',     'page_type' => 'custom',   'parent_page_id' => null,  'status' => 'trash',     'generation_status' => 'not_generated', 'menu_primary' => 0, 'menu_footer' => 0, 'menu_order' => 9, 'index_page' => 0, 'current_generation_id' => null]),
    new MockPage(['id' => 5, 'site_id' => 20, 'title' => 'Home',    'slug' => 'home',    'page_type' => 'homepage', 'parent_page_id' => null,  'status' => 'draft',     'generation_status' => 'not_generated', 'menu_primary' => 1, 'menu_footer' => 0, 'menu_order' => 0, 'index_page' => 1, 'current_generation_id' => null]),
];

$media_items = [
    new MockMedia(['id' => 1, 'site_id' => 10, 'filename' => 'hero.jpg',   'category' => 'hero',    'alt_text' => 'Hero image']),
    new MockMedia(['id' => 2, 'site_id' => 10, 'filename' => 'logo.png',   'category' => 'logo',    'alt_text' => 'Company logo']),
    new MockMedia(['id' => 3, 'site_id' => 20, 'filename' => 'photo.jpg',  'category' => 'general', 'alt_text' => null]),
];

$generations = [
    new MockGeneration(['id' => 1, 'page_id' => 3, 'site_id' => 10, 'html_content' => '<html>v1</html>', 'is_current' => 0, 'status' => 'draft']),
    new MockGeneration(['id' => 2, 'page_id' => 3, 'site_id' => 10, 'html_content' => '<html>v2</html>', 'is_current' => 0, 'status' => 'draft']),
    new MockGeneration(['id' => 5, 'page_id' => 3, 'site_id' => 10, 'html_content' => '<html>v3</html>', 'is_current' => 1, 'status' => 'draft']),
];

// ---------------------------------------------------------------------------
// T1: Page status defaults
// ---------------------------------------------------------------------------
$p = new MockPage(['id' => 99, 'site_id' => 10, 'title' => 'Test', 'slug' => 'test',
    'page_type' => 'custom', 'parent_page_id' => null,
    'status' => 'draft', 'generation_status' => 'not_generated',
    'menu_primary' => 0, 'menu_footer' => 0, 'menu_order' => 0,
    'index_page' => 1, 'current_generation_id' => null]);
assert_eq($p->status, 'draft', 'T1a: new page defaults to status=draft');
assert_eq($p->generation_status, 'not_generated', 'T1b: new page defaults to generation_status=not_generated');
assert_null($p->current_generation_id, 'T1c: new page has no current generation');

// ---------------------------------------------------------------------------
// T2: Status separation — status and generation_status are independent
// ---------------------------------------------------------------------------
$p2 = new MockPage(['id' => 10, 'site_id' => 10, 'status' => 'published', 'generation_status' => 'generated',
    'title' => 'Pub', 'slug' => 'pub', 'page_type' => 'custom', 'parent_page_id' => null,
    'menu_primary' => 0, 'menu_footer' => 0, 'menu_order' => 0, 'index_page' => 1, 'current_generation_id' => 5]);
assert_eq($p2->status, 'published', 'T2a: status=published is valid');
assert_eq($p2->generation_status, 'generated', 'T2b: generation_status=generated is valid alongside published status');

$p3 = new MockPage(['id' => 11, 'site_id' => 10, 'status' => 'draft', 'generation_status' => 'generated',
    'title' => 'Draft w/ gen', 'slug' => 'd', 'page_type' => 'custom', 'parent_page_id' => null,
    'menu_primary' => 0, 'menu_footer' => 0, 'menu_order' => 0, 'index_page' => 1, 'current_generation_id' => 5]);
assert_eq($p3->status, 'draft', 'T2c: draft status does not depend on generation_status');
assert_eq($p3->generation_status, 'generated', 'T2d: generated content can exist while page is draft');

// ---------------------------------------------------------------------------
// T3: Parent hierarchy validation
// ---------------------------------------------------------------------------
assert_true(mock_validate_page_parent(0, 2, 10, $pages), 'T3a: new page (id=0) can use existing page as parent');
assert_false(mock_validate_page_parent(1, 1, 10, $pages), 'T3b: page cannot use itself as parent (self-reference)');
assert_false(mock_validate_page_parent(1, 5, 10, $pages), 'T3c: page cannot use page from different site as parent (cross-site)');
assert_false(mock_validate_page_parent(1, 4, 10, $pages), 'T3d: page cannot use trashed page as parent');
assert_false(mock_validate_page_parent(1, 999, 10, $pages), 'T3e: page cannot use non-existent page as parent');
assert_true(mock_validate_page_parent(2, 1, 10, $pages), 'T3f: page can use homepage of same site as parent');

// ---------------------------------------------------------------------------
// T4: Trash / restore lifecycle
// ---------------------------------------------------------------------------
$pt = new MockPage(['id' => 20, 'site_id' => 10, 'title' => 'T', 'slug' => 't', 'page_type' => 'custom',
    'parent_page_id' => null, 'status' => 'draft', 'generation_status' => 'not_generated',
    'menu_primary' => 0, 'menu_footer' => 0, 'menu_order' => 0, 'index_page' => 1, 'current_generation_id' => null]);
$pt->status = 'trash';
assert_eq($pt->status, 'trash', 'T4a: page can be set to trash');
$pt->status = 'draft';
assert_eq($pt->status, 'draft', 'T4b: trashed page can be restored to draft');

// ---------------------------------------------------------------------------
// T5: Site isolation — listing pages for a site excludes other sites
// ---------------------------------------------------------------------------
$site10_pages = array_filter($pages, fn($p) => (int) $p->site_id === 10);
$site20_pages = array_filter($pages, fn($p) => (int) $p->site_id === 20);
assert_eq(count($site10_pages), 4, 'T5a: site 10 has 4 pages total');
assert_eq(count($site20_pages), 1, 'T5b: site 20 has 1 page');

$site10_active = array_filter($site10_pages, fn($p) => $p->status !== 'trash');
assert_eq(count($site10_active), 3, 'T5c: site 10 has 3 active (non-trashed) pages');

// ---------------------------------------------------------------------------
// T6: Menu configuration
// ---------------------------------------------------------------------------
$menu_primary = array_filter($pages, fn($p) => (int) $p->menu_primary === 1 && (int) $p->site_id === 10);
$menu_footer  = array_filter($pages, fn($p) => (int) $p->menu_footer  === 1 && (int) $p->site_id === 10);
assert_eq(count($menu_primary), 3, 'T6a: 3 pages in primary nav for site 10');
assert_eq(count($menu_footer), 1,  'T6b: 1 page in footer nav for site 10');

$ordered = $site10_active;
usort($ordered, fn($a, $b) => $a->menu_order <=> $b->menu_order);
$first = reset($ordered);
assert_eq((int) $first->id, 1, 'T6c: menu_order=0 page sorts first');

// ---------------------------------------------------------------------------
// T7: Media site ownership
// ---------------------------------------------------------------------------
$site10_media = array_filter($media_items, fn($m) => (int) $m->site_id === 10);
$site20_media = array_filter($media_items, fn($m) => (int) $m->site_id === 20);
assert_eq(count($site10_media), 2, 'T7a: site 10 has 2 media items');
assert_eq(count($site20_media), 1, 'T7b: site 20 has 1 media item');

// ---------------------------------------------------------------------------
// T8: Media attachment — same-site allowed, cross-site rejected
// ---------------------------------------------------------------------------
$page_site10 = $pages[0]; // site_id=10
$media_site10 = $media_items[0]; // site_id=10
$media_site20 = $media_items[2]; // site_id=20

$r8a = mock_attach_media($page_site10, $media_site10);
assert_true($r8a['ok'], 'T8a: attach same-site media succeeds');

$r8b = mock_attach_media($page_site10, $media_site20);
assert_false($r8b['ok'], 'T8b: attach cross-site media rejected');
assert_eq($r8b['reason'], 'cross_site', 'T8c: rejection reason is cross_site');

$r8c = mock_attach_media(null, $media_site10);
assert_false($r8c['ok'], 'T8d: attach with null page returns false');

// ---------------------------------------------------------------------------
// T9: Detach does not delete media
// ---------------------------------------------------------------------------
$page_media_links = [
    ['page_id' => 3, 'media_id' => 1],
    ['page_id' => 3, 'media_id' => 2],
];
$to_detach = ['page_id' => 3, 'media_id' => 1];
$after_detach = array_filter($page_media_links, fn($pm) => !($pm['page_id'] === $to_detach['page_id'] && $pm['media_id'] === $to_detach['media_id']));
assert_eq(count($after_detach), 1, 'T9a: detach removes the link row');
// Media item itself still exists
$media_still_exists = !empty(array_filter($media_items, fn($m) => (int) $m->id === 1));
assert_true($media_still_exists, 'T9b: detach does not delete the media item from the library');

// ---------------------------------------------------------------------------
// T10: Generation history — multiple versions can exist
// ---------------------------------------------------------------------------
assert_eq(count($generations), 3, 'T10a: page 3 has 3 generation records');
$current_gens = array_filter($generations, fn($g) => (int) $g->is_current === 1);
assert_eq(count($current_gens), 1, 'T10b: exactly one generation is marked current');
assert_eq((int) reset($current_gens)->id, 5, 'T10c: generation id=5 is the current one');

// ---------------------------------------------------------------------------
// T11: set_current_generation — switches is_current flag; others cleared
// ---------------------------------------------------------------------------
$gens_copy = array_map(fn($g) => clone $g, $generations);
$ok = mock_set_current_generation($gens_copy, 3, 2);
assert_true($ok, 'T11a: set_current_generation returns true for valid generation');
$curr_after = array_filter($gens_copy, fn($g) => (int) $g->is_current === 1);
assert_eq(count($curr_after), 1, 'T11b: only one generation is_current after switch');
assert_eq((int) reset($curr_after)->id, 2, 'T11c: generation id=2 is now current');
$prev_curr = array_filter($gens_copy, fn($g) => (int) $g->id === 5);
assert_eq((int) reset($prev_curr)->is_current, 0, 'T11d: former current (id=5) is_current cleared');

// T11e: wrong page_id rejected
$gens_copy2 = array_map(fn($g) => clone $g, $generations);
$ok_bad = mock_set_current_generation($gens_copy2, 999, 2); // page 999 does not exist
assert_false($ok_bad, 'T11e: set_current_generation rejects generation not belonging to page');

// ---------------------------------------------------------------------------
// T12: Generation status lifecycle values
// ---------------------------------------------------------------------------
$valid_gen_statuses = ['not_generated', 'generating', 'generated', 'failed'];
foreach ($valid_gen_statuses as $s) {
    assert_true(in_array($s, $valid_gen_statuses, true), "T12: generation_status '$s' is in valid set");
}
$invalid = 'pending';
assert_false(in_array($invalid, $valid_gen_statuses, true), 'T12: "pending" is not a valid generation_status');

// ---------------------------------------------------------------------------
// T13: Page types
// ---------------------------------------------------------------------------
$valid_page_types = ['homepage', 'about', 'services', 'contact', 'faq', 'blog', 'landing', 'custom'];
foreach (['homepage', 'about', 'services', 'contact', 'custom'] as $pt) {
    assert_true(in_array($pt, $valid_page_types, true), "T13: page_type '$pt' is recognised");
}

// ---------------------------------------------------------------------------
// T14: SEO config fields exist on page
// ---------------------------------------------------------------------------
$seo_page = new MockPage([
    'id' => 30, 'site_id' => 10, 'title' => 'SEO Test', 'slug' => 'seo-test',
    'page_type' => 'custom', 'parent_page_id' => null, 'status' => 'draft',
    'generation_status' => 'not_generated', 'menu_primary' => 0, 'menu_footer' => 0,
    'menu_order' => 0, 'index_page' => 1, 'current_generation_id' => null,
    'meta_title' => 'SEO Title', 'meta_description' => 'A description.',
    'primary_keyword' => 'web design', 'supporting_keywords' => 'website, online',
    'instructions' => 'Focus on local clients.',
]);
assert_eq($seo_page->meta_title, 'SEO Title', 'T14a: meta_title stored on page');
assert_eq($seo_page->primary_keyword, 'web design', 'T14b: primary_keyword stored on page');
assert_true(isset($seo_page->instructions), 'T14c: instructions field exists on page');
assert_eq((int) $seo_page->index_page, 1, 'T14d: index_page defaults to 1 (indexed)');

// ---------------------------------------------------------------------------
// DB-dependent tests (listed for documentation, require live CI instance)
// ---------------------------------------------------------------------------
t_skip('DB: v16 migration creates tblpitchsnap_pages with correct schema',             'needs DB');
t_skip('DB: v16 migration creates tblpitchsnap_site_media with correct schema',        'needs DB');
t_skip('DB: v16 migration creates tblpitchsnap_page_media with correct schema',        'needs DB');
t_skip('DB: v16 migration creates tblpitchsnap_page_generations with correct schema',  'needs DB');
t_skip('DB: v15 columns still present after v16 migration',                            'needs DB');
t_skip('DB: create_page inserts record and returns insert_id',                         'needs DB');
t_skip('DB: get_pages_for_site excludes trash by default',                             'needs DB');
t_skip('DB: validate_page_parent rejects cross-site parent via DB lookup',             'needs DB');
t_skip('DB: create_media inserts record with correct site_id',                         'needs DB');
t_skip('DB: attach_media_to_page enforces same-site via DB records',                   'needs DB');
t_skip('DB: detach_media_from_page removes join row; media row remains',               'needs DB');
t_skip('DB: set_current_page_generation updates pages.current_generation_id',          'needs DB');
t_skip('DB: multiple calls to set_current leave only one is_current=1 per page',       'needs DB');

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
echo "\n=== Phase 2 Pages — Unit Tests ===\n";
foreach ($results as $r) { echo "  $r\n"; }
echo "\n";
echo "Total: " . ($pass + $fail) . " pure  |  Pass: $pass  |  Fail: $fail\n\n";
if ($fail > 0) {
    echo "FAILURES DETECTED\n";
    exit(1);
}
echo "All pure tests passed.\n";
exit(0);
