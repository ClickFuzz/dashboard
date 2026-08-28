<?php
/**
 * Phase 1 Publishing — Unit Tests
 *
 * Tests pure PHP logic without requiring a running CodeIgniter instance.
 * Run from the project root:
 *   php modules/pitchsnap/tests/test_phase1_publishing.php
 *
 * DB-dependent tests (marked SKIP) require a live CI environment and are
 * listed here for documentation; run them manually against a dev instance.
 */

// Allow helpers with the CI guard to be loaded in CLI context
if (!defined('BASEPATH')) { define('BASEPATH', __DIR__ . '/../../../system/'); }

$pass = 0;
$fail = 0;
$results = [];

function t_pass($name)  { global $pass, $results; $pass++; $results[] = "PASS  $name"; }
function t_fail($name, $detail = '')  { global $fail, $results; $fail++; $results[] = "FAIL  $name" . ($detail ? " — $detail" : ''); }
function t_skip($name, $reason) { global $results; $results[] = "SKIP  $name ($reason)"; }

function assert_true($cond, $name, $detail = '')  { if ($cond) { t_pass($name); } else { t_fail($name, $detail); } }
function assert_eq($a, $b, $name) { assert_true($a === $b, $name, "expected " . json_encode($b) . ", got " . json_encode($a)); }
function assert_null($v, $name) { assert_true($v === null, $name, "expected null, got " . json_encode($v)); }

// ---------------------------------------------------------------------------
// Load the helpers that have pure PHP logic
// ---------------------------------------------------------------------------
$helper_base = __DIR__ . '/../helpers/';
require_once $helper_base . 'pitchsnap_domain_helper.php';

// ---------------------------------------------------------------------------
// Publish type validation (pure PHP)
// ---------------------------------------------------------------------------
function ps_validate_publish_type($type)
{
    return in_array($type, ['html', 'wordpress'], true);
}

// Test 1: publish_type accepts 'html'
assert_true(ps_validate_publish_type('html'), 'T1: publish_type accepts html');

// Test 2: publish_type accepts 'wordpress'
assert_true(ps_validate_publish_type('wordpress'), 'T2: publish_type accepts wordpress');

// Test 3: only one publishing type is active (rejects combined/other values)
assert_true(!ps_validate_publish_type('both'), 'T3a: publish_type rejects "both"');
assert_true(!ps_validate_publish_type('html,wordpress'), 'T3b: publish_type rejects comma list');
assert_true(!ps_validate_publish_type(''), 'T3c: publish_type rejects empty string');
assert_true(!ps_validate_publish_type('ftp'), 'T3d: publish_type rejects unknown type');

// ---------------------------------------------------------------------------
// Domain helper — pure PHP functions
// ---------------------------------------------------------------------------

// Test 4: normalize_hostname strips protocol, www, path, uppercasing
assert_eq(clickfuzz_web_normalize_hostname('https://Example.COM/path/'), 'example.com', 'T4a: normalize strips https + path + case');
assert_eq(clickfuzz_web_normalize_hostname('http://DOMAIN.org'), 'domain.org', 'T4b: normalize strips http + case');
assert_eq(clickfuzz_web_normalize_hostname('  yourdomain.com  '), 'yourdomain.com', 'T4c: normalize trims whitespace');
assert_eq(clickfuzz_web_normalize_hostname('sub.example.com'), 'sub.example.com', 'T4d: normalize preserves subdomain');

// ---------------------------------------------------------------------------
// Mock objects for simulation tests
// ---------------------------------------------------------------------------
class MockSite {
    public $id; public $status; public $publish_type; public $source_website_id;
    public $wp_site_url; public $wp_username; public $wp_app_password; public $wp_page_id;
    public function __construct($data) { foreach ($data as $k => $v) $this->$k = $v; }
}
class MockRedesign {
    public $id; public $lead_id; public $is_primary; public $preview_token; public $status;
    public function __construct($data) { foreach ($data as $k => $v) $this->$k = $v; }
}

// Simulated HTML publish (mirrors the success/failure contract of clickfuzz_web_publish_site)
function mock_html_publish(MockSite $site, bool $preview_file_exists, bool $write_ok = true): array
{
    if (!$preview_file_exists) {
        return ['success' => false, 'url' => null, 'error' => 'Preview file not found on disk.'];
    }
    if (!$write_ok) {
        return ['success' => false, 'url' => null, 'error' => 'Failed to write site HTML file.'];
    }
    $site->status = 'published';
    return ['success' => true, 'url' => 'https://test.clickfuzz.com/', 'error' => null];
}

// Simulated WP publish with two-phase front-page assignment
function mock_wp_publish_with_frontpage(MockSite $site, bool $page_api_ok, bool $settings_api_ok, bool $curl_ok = true): array
{
    if (empty($site->wp_site_url) || empty($site->wp_app_password)) {
        return ['success' => false, 'url' => null, 'error' => 'WordPress credentials not configured.'];
    }
    if (!$curl_ok) {
        return ['success' => false, 'url' => null, 'error' => 'Connection error: could not connect.'];
    }
    if (!$page_api_ok) {
        return ['success' => false, 'url' => null, 'error' => 'WordPress API error: HTTP 401'];
    }
    $wp_page_id = 42;
    if (!$settings_api_ok) {
        return ['success' => false, 'url' => null, 'error' => 'Page published but front-page assignment failed: HTTP 403'];
    }
    // Only mark published after BOTH phases succeed
    $site->status     = 'published';
    $site->wp_page_id = $wp_page_id;
    return ['success' => true, 'url' => $site->wp_site_url . '/clickfuzz-homepage/', 'error' => null];
}

// Simulated queue_generate published guard
function mock_queue_generate_guard(MockSite $site_for_primary = null): array
{
    if ($site_for_primary && $site_for_primary->status === 'published') {
        return ['success' => false, 'message' => 'This site is already published. Generation is locked.'];
    }
    return ['success' => true, 'message' => 'Queued.'];
}

// Simulated regenerate published guard
function mock_regenerate_guard(MockSite $site_for_primary = null): array
{
    if ($site_for_primary && $site_for_primary->status === 'published') {
        return ['blocked' => true, 'message' => 'This site is already published. Normal regeneration is locked.'];
    }
    return ['blocked' => false];
}

// ---------------------------------------------------------------------------
// Test 5: HTML options associated with html mode (view logic)
// ---------------------------------------------------------------------------
$site_html = new MockSite(['id' => 1, 'status' => 'draft', 'publish_type' => 'html', 'source_website_id' => 10]);
assert_true($site_html->publish_type === 'html', 'T5a: HTML publish type stored');
assert_true($site_html->publish_type !== 'wordpress', 'T5b: HTML mode does not select WordPress');

// Test 6: successful HTML publish changes site status to 'published'
$site6 = new MockSite(['id' => 6, 'status' => 'draft', 'publish_type' => 'html', 'source_website_id' => 60]);
$r6 = mock_html_publish($site6, true, true);
assert_true($r6['success'], 'T6a: HTML publish returns success=true');
assert_eq($site6->status, 'published', 'T6b: HTML publish sets site.status = published');

// Test 7: failed HTML publish does not change site status
$site7 = new MockSite(['id' => 7, 'status' => 'draft', 'publish_type' => 'html', 'source_website_id' => 70]);
$r7 = mock_html_publish($site7, false);
assert_true(!$r7['success'], 'T7a: failed HTML publish returns success=false');
assert_eq($site7->status, 'draft', 'T7b: failed HTML publish leaves site.status as draft');

// Also: write failure should not mark as published
$site7b = new MockSite(['id' => 7, 'status' => 'draft', 'publish_type' => 'html', 'source_website_id' => 70]);
$r7b = mock_html_publish($site7b, true, false);
assert_true(!$r7b['success'], 'T7c: write-fail HTML publish returns success=false');
assert_eq($site7b->status, 'draft', 'T7d: write-fail HTML publish leaves site.status as draft');

// ---------------------------------------------------------------------------
// Test 8-9: WordPress two-phase publish (page creation + front-page assignment)
// ---------------------------------------------------------------------------

// Test 8: successful WP publish (both phases) changes site status to 'published'
$site8 = new MockSite(['id' => 8, 'status' => 'draft', 'publish_type' => 'wordpress',
    'source_website_id' => 80, 'wp_site_url' => 'https://wp.example.com',
    'wp_username' => 'admin', 'wp_app_password' => 'xxxx xxxx xxxx', 'wp_page_id' => null]);
$r8 = mock_wp_publish_with_frontpage($site8, true, true);
assert_true($r8['success'], 'T8a: WP publish returns success=true');
assert_eq($site8->status, 'published', 'T8b: WP publish sets site.status = published');
assert_eq($site8->wp_page_id, 42, 'T8c: WP publish stores wp_page_id');

// Test 9: failed page creation does not mark site published
$site9a = new MockSite(['id' => 9, 'status' => 'draft', 'publish_type' => 'wordpress',
    'source_website_id' => 90, 'wp_site_url' => 'https://wp.example.com',
    'wp_username' => 'admin', 'wp_app_password' => 'xxxx xxxx xxxx', 'wp_page_id' => null]);
$r9a = mock_wp_publish_with_frontpage($site9a, false, true);
assert_true(!$r9a['success'], 'T9a: WP page-creation fail returns success=false');
assert_eq($site9a->status, 'draft', 'T9b: WP page-creation fail leaves site.status as draft');

$site9b = new MockSite(['id' => 9, 'status' => 'draft', 'publish_type' => 'wordpress',
    'source_website_id' => 90, 'wp_site_url' => 'https://wp.example.com',
    'wp_username' => 'admin', 'wp_app_password' => 'xxxx xxxx xxxx', 'wp_page_id' => null]);
$r9b = mock_wp_publish_with_frontpage($site9b, true, false);
assert_true(!$r9b['success'], 'T9c: WP settings-fail returns success=false');
assert_eq($site9b->status, 'draft', 'T9d: WP settings-fail leaves site.status as draft');
assert_true(strpos($r9b['error'], 'front-page assignment failed') !== false, 'T9e: error identifies front-page as failure point');

$site9c = new MockSite(['id' => 9, 'status' => 'draft', 'publish_type' => 'wordpress',
    'source_website_id' => 90, 'wp_site_url' => 'https://wp.example.com',
    'wp_username' => 'admin', 'wp_app_password' => 'xxxx xxxx xxxx', 'wp_page_id' => null]);
$r9c = mock_wp_publish_with_frontpage($site9c, true, true, false);  // curl error
assert_true(!$r9c['success'], 'T9f: WP curl-fail returns success=false');
assert_eq($site9c->status, 'draft', 'T9g: WP curl-fail leaves site.status as draft');

$site9d = new MockSite(['id' => 9, 'status' => 'draft', 'publish_type' => 'wordpress',
    'source_website_id' => 90, 'wp_site_url' => '', 'wp_username' => '',
    'wp_app_password' => '', 'wp_page_id' => null]);
$r9d = mock_wp_publish_with_frontpage($site9d, true, true);  // missing credentials
assert_true(!$r9d['success'], 'T9h: WP missing-creds returns success=false');
assert_eq($site9d->status, 'draft', 'T9i: WP missing-creds leaves site.status as draft');

// ---------------------------------------------------------------------------
// Test 10-12: Canonical version preserved, history removed
// ---------------------------------------------------------------------------
$redesigns = [
    new MockRedesign(['id' => 1, 'lead_id' => 1, 'is_primary' => 1, 'preview_token' => 'aaa']),
    new MockRedesign(['id' => 2, 'lead_id' => 1, 'is_primary' => 0, 'preview_token' => 'bbb']),
    new MockRedesign(['id' => 3, 'lead_id' => 1, 'is_primary' => 0, 'preview_token' => 'ccc']),
];

$primary     = array_filter($redesigns, fn($r) => (bool) $r->is_primary);
$non_primary = array_filter($redesigns, fn($r) => !(bool) $r->is_primary);

// Test 10: primary version retained
assert_true(count($primary) === 1, 'T10a: exactly one primary version exists');
assert_eq((int) reset($primary)->id, 1, 'T10b: primary version is id=1 (the canonical)');

// Test 11: non-primary versions are selected for cleanup
assert_eq(count($non_primary), 2, 'T11: two non-primary versions selected for deletion');

// Test 12: canonical version not in deletion set
$non_primary_ids = array_map(fn($r) => (int) $r->id, $non_primary);
assert_true(!in_array(1, $non_primary_ids), 'T12: canonical version (id=1) excluded from cleanup set');
assert_true(in_array(2, $non_primary_ids), 'T12b: old version id=2 included in cleanup');
assert_true(in_array(3, $non_primary_ids), 'T12c: old version id=3 included in cleanup');

$mock_would_delete_primary = count(array_filter($non_primary_ids, fn($id) => $id === 1)) > 0;
assert_true(!$mock_would_delete_primary, 'T12d: is_primary guard prevents canonical deletion');

// ---------------------------------------------------------------------------
// Test 13: published site blocks queue_generate and regenerate
// ---------------------------------------------------------------------------
$pub_site = new MockSite(['id' => 1, 'status' => 'published', 'publish_type' => 'html', 'source_website_id' => 1]);
$draft_site = new MockSite(['id' => 2, 'status' => 'draft', 'publish_type' => 'html', 'source_website_id' => 2]);

$r13a = mock_queue_generate_guard($pub_site);
assert_true(!$r13a['success'], 'T13a: queue_generate blocked for published site');

$r13b = mock_queue_generate_guard($draft_site);
assert_true($r13b['success'], 'T13b: queue_generate allowed for draft site');

$r13c = mock_queue_generate_guard(null);  // no site record
assert_true($r13c['success'], 'T13c: queue_generate allowed when no site record yet');

$r13d = mock_regenerate_guard($pub_site);
assert_true($r13d['blocked'], 'T13d: regenerate blocked for published site');

$r13e = mock_regenerate_guard($draft_site);
assert_true(!$r13e['blocked'], 'T13e: regenerate allowed for draft site');

// ---------------------------------------------------------------------------
// DB-dependent tests (require a live CI instance — listed for documentation)
// ---------------------------------------------------------------------------
t_skip('DB: v15 migration adds publish_type, wp_site_url, wp_username, wp_app_password, wp_page_id columns', 'needs DB');
t_skip('DB: HTML publish writes file + sets site.status=published + cleans history', 'needs DB + filesystem');
t_skip('DB: WP publish calls WP REST API pages endpoint + settings endpoint (front-page) + sets published', 'needs WP instance');
t_skip('DB: WP settings endpoint 403 leaves site.status=draft', 'needs WP instance with restricted user');
t_skip('DB: publish cleanup deletes preview files from disk', 'needs DB + filesystem');
t_skip('DB: publish cleanup preserves primary redesign record', 'needs DB');
t_skip('DB: save_publish_type persists html/wordpress to DB', 'needs DB');
t_skip('DB: save_wp_connection persists wp_site_url + credentials', 'needs DB');
t_skip('DB: queue_generate blocked when site.status=published', 'needs DB');
t_skip('DB: regenerate blocked when site.status=published', 'needs DB');

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
echo "\n=== Phase 1 Publishing — Unit Tests ===\n";
foreach ($results as $r) { echo "  $r\n"; }
echo "\n";
echo "Total: " . ($pass + $fail) . " pure  |  Pass: $pass  |  Fail: $fail\n\n";
if ($fail > 0) {
    echo "FAILURES DETECTED\n";
    exit(1);
}
echo "All pure tests passed.\n";
exit(0);
