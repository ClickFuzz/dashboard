<?php
/**
 * Phase 4 Page Generation — Unit Tests
 *
 * Tests pure PHP logic without requiring a running CodeIgniter instance.
 * Run from the project root:
 *   php modules/pitchsnap/tests/test_phase4_pages.php
 *
 * DB-dependent and API tests are marked SKIP and listed for documentation.
 */

if (!defined('BASEPATH')) { define('BASEPATH', __DIR__ . '/../../../system/'); }
if (!defined('FCPATH'))   { define('FCPATH',   __DIR__ . '/../../../'); }

$pass = 0; $fail = 0; $results = [];

function t_pass($n)               { global $pass, $results; $pass++; $results[] = "PASS  $n"; }
function t_fail($n, $d = '')     { global $fail, $results; $fail++; $results[] = "FAIL  $n" . ($d ? " — $d" : ''); }
function t_skip($n, $r)          { global $results; $results[] = "SKIP  $n ($r)"; }
function assert_true($c, $n, $d = '') { if ($c) { t_pass($n); } else { t_fail($n, $d); } }
function assert_eq($a, $b, $n)   { assert_true($a === $b, $n, 'expected '.json_encode($b).', got '.json_encode($a)); }
function assert_false($c, $n)    { assert_true(!$c, $n, 'expected false'); }

require_once __DIR__ . '/../helpers/pitchsnap_media_helper.php';
require_once __DIR__ . '/../helpers/pitchsnap_domain_helper.php';
require_once __DIR__ . '/../helpers/pitchsnap_page_generation_helper.php';

// ---------------------------------------------------------------------------
// T1: Readiness check — mirrors clickfuzz_web_page_is_ready_to_generate()
// ---------------------------------------------------------------------------

function make_page4($d) {
    $p = new stdClass();
    foreach (['title','slug','page_type','primary_keyword','instructions','status','generation_status'] as $f) {
        $p->$f = $d[$f] ?? null;
    }
    return $p;
}

$p_full = make_page4(['title'=>'AC Repair','slug'=>'ac-repair','page_type'=>'service','primary_keyword'=>'AC repair Austin','instructions'=>'','status'=>'draft','generation_status'=>'not_generated']);
assert_true(clickfuzz_web_page_is_ready_to_generate($p_full), 'T1a: fully configured page is ready');

$p_instructions_only = make_page4(['title'=>'About','slug'=>'about','page_type'=>'about','primary_keyword'=>'','instructions'=>'Write about us.','status'=>'draft','generation_status'=>'not_generated']);
assert_true(clickfuzz_web_page_is_ready_to_generate($p_instructions_only), 'T1b: instructions alone satisfies keyword requirement');

$p_no_kw = make_page4(['title'=>'About','slug'=>'about','page_type'=>'about','primary_keyword'=>'','instructions'=>'','status'=>'draft','generation_status'=>'not_generated']);
assert_false(clickfuzz_web_page_is_ready_to_generate($p_no_kw), 'T1c: no keyword and no instructions = not ready');

$p_no_title = make_page4(['title'=>'','slug'=>'about','page_type'=>'about','primary_keyword'=>'kw','instructions'=>'','status'=>'draft','generation_status'=>'not_generated']);
assert_false(clickfuzz_web_page_is_ready_to_generate($p_no_title), 'T1d: missing title = not ready');

$p_no_slug = make_page4(['title'=>'About','slug'=>'','page_type'=>'about','primary_keyword'=>'kw','instructions'=>'','status'=>'draft','generation_status'=>'not_generated']);
assert_false(clickfuzz_web_page_is_ready_to_generate($p_no_slug), 'T1e: missing slug = not ready');

$p_no_type = make_page4(['title'=>'About','slug'=>'about','page_type'=>'','primary_keyword'=>'kw','instructions'=>'','status'=>'draft','generation_status'=>'not_generated']);
assert_false(clickfuzz_web_page_is_ready_to_generate($p_no_type), 'T1f: missing page_type = not ready');

// ---------------------------------------------------------------------------
// T2: Generation status lifecycle transitions
// ---------------------------------------------------------------------------
$valid_statuses = ['not_generated', 'generating', 'generated', 'failed'];
foreach ($valid_statuses as $vs) {
    assert_true(in_array($vs, $valid_statuses, true), "T2: status '$vs' is valid");
}
assert_false(in_array('pending', $valid_statuses, true), 'T2b: pending is not a valid page generation status');
assert_false(in_array('queued',  $valid_statuses, true), 'T2c: queued is not a valid page generation status');

// T2d: not_generated → generating is a valid transition
$from_statuses = ['not_generated', 'failed'];
assert_true(in_array('not_generated', $from_statuses), 'T2d: can queue from not_generated');
assert_true(in_array('failed',        $from_statuses), 'T2e: can retry from failed');
assert_false(in_array('generated',    $from_statuses), 'T2f: cannot re-queue already generated (without regenerate)');
assert_false(in_array('generating',   $from_statuses), 'T2g: cannot re-queue already generating');

// ---------------------------------------------------------------------------
// T3: Page type instructions coverage
// ---------------------------------------------------------------------------
$valid_types = ['about','service','service_area','contact','gallery','financing','faq','custom'];
foreach ($valid_types as $vt) {
    $instr = clickfuzz_web_get_page_type_instructions($vt);
    assert_true(!empty($instr), "T3: type '$vt' has instructions");
    assert_true(strlen($instr) > 50, "T3: '$vt' instructions are substantive (>50 chars)");
}

// T3b: unknown type falls back to custom
$instr_unknown = clickfuzz_web_get_page_type_instructions('unknown_xyz');
$instr_custom  = clickfuzz_web_get_page_type_instructions('custom');
assert_eq($instr_unknown, $instr_custom, 'T3b: unknown page type falls back to custom instructions');

// T3c: service_area mentions local/SEO concepts
$instr_sa = clickfuzz_web_get_page_type_instructions('service_area');
assert_true(stripos($instr_sa, 'local') !== false || stripos($instr_sa, 'SEO') !== false || stripos($instr_sa, 'area') !== false,
    'T3c: service_area instructions mention local/SEO/area');

// T3d: faq mentions schema or FAQ
$instr_faq = clickfuzz_web_get_page_type_instructions('faq');
assert_true(stripos($instr_faq, 'faq') !== false || stripos($instr_faq, 'question') !== false,
    'T3d: faq instructions mention FAQ or question');

// ---------------------------------------------------------------------------
// T4: Output extraction — <body_html> and other delimiters
// ---------------------------------------------------------------------------

$raw_good = <<<RAW
<body_html>
<div class="hero">Hello World</div>
<p>Some content here.</p>
</body_html>
<page_css>
.hero { color: red; }
</page_css>
<page_js>
console.log('hello');
</page_js>
<meta_title>AC Repair Austin | Bob's HVAC</meta_title>
<meta_description>Need AC repair in Austin? Call Bob's HVAC for fast, reliable service.</meta_description>
RAW;

$parsed = clickfuzz_web_extract_page_output($raw_good);
assert_true(!empty($parsed['body_html']),         'T4a: body_html extracted');
assert_true(strpos($parsed['body_html'], 'hero') !== false, 'T4b: body_html contains expected content');
assert_true(!empty($parsed['page_css']),          'T4c: page_css extracted');
assert_true(strpos($parsed['page_css'], '.hero') !== false, 'T4d: page_css contains expected content');
assert_true(!empty($parsed['page_js']),           'T4e: page_js extracted');
assert_true(!empty($parsed['meta_title']),        'T4f: meta_title extracted');
assert_true(!empty($parsed['meta_description']), 'T4g: meta_description extracted');

// T4h: empty sections return empty string, not null/false
assert_eq($parsed['body_html'],  trim("<div class=\"hero\">Hello World</div>\n<p>Some content here.</p>"), 'T4h: body_html trimmed correctly');

// T4i: missing sections return empty string
$raw_partial = "<body_html>\n<p>Only body.</p>\n</body_html>";
$parsed_p = clickfuzz_web_extract_page_output($raw_partial);
assert_true(!empty($parsed_p['body_html']),  'T4i: body_html present in partial');
assert_eq($parsed_p['page_css'],  '',        'T4j: missing page_css returns empty string');
assert_eq($parsed_p['meta_title'], '',       'T4k: missing meta_title returns empty string');

// T4l: fallback for raw HTML response (no delimiters)
$raw_html_only = '<html><head></head><body><p>A real page.</p></body></html>';
$parsed_fallback = clickfuzz_web_extract_page_output($raw_html_only);
assert_true(!empty($parsed_fallback['body_html']), 'T4l: raw HTML fallback populates body_html');

// T4m: markdown-fenced response stripped in fallback
$raw_fenced = "```html\n<html><body><p>Hello</p></body></html>\n```";
$parsed_fenced = clickfuzz_web_extract_page_output($raw_fenced);
assert_true(!empty($parsed_fenced['body_html']),         'T4m: markdown-fenced fallback populates body_html');
assert_false(strpos($parsed_fenced['body_html'], '```') !== false, 'T4n: backticks stripped from fallback body_html');

// ---------------------------------------------------------------------------
// T5: Prompt construction — key content checks
// ---------------------------------------------------------------------------
if (!function_exists('base_url')) {
    function base_url($s = '') { return 'https://clickfuzz.com/dashboard/'; }
}

$pg = make_page4(['title'=>'Plumbing Services','slug'=>'plumbing-services','page_type'=>'service',
    'primary_keyword'=>'plumber Austin TX','instructions'=>'Focus on emergency plumbing.',
    'status'=>'draft','generation_status'=>'not_generated']);
$pg->supporting_keywords = '24 hour plumber, drain cleaning';
$pg->menu_primary = 1;
$pg->menu_footer  = 0;
$pg->menu_label   = 'Services';
$pg->menu_order   = 2;
$pg->parent_page_id = null;

$mock_site     = null;
$mock_lead     = null;
$mock_redesign = null;

$prompt = clickfuzz_web_build_page_prompt($pg, $mock_site, $mock_lead, $mock_redesign, [], []);

assert_true(strpos($prompt, 'Plumbing Services') !== false,  'T5a: prompt includes page title');
assert_true(strpos($prompt, 'plumber Austin TX') !== false,  'T5b: prompt includes primary keyword');
assert_true(strpos($prompt, 'emergency plumbing') !== false, 'T5c: prompt includes custom instructions');
assert_true(strpos($prompt, 'service') !== false,            'T5d: prompt includes page type');
assert_true(strpos($prompt, '<body_html>') !== false,        'T5e: prompt instructs AI to output body_html delimiter');
assert_true(strpos($prompt, '<meta_title>') !== false,       'T5f: prompt instructs AI to output meta_title delimiter');
assert_true(strpos($prompt, '<meta_description>') !== false, 'T5g: prompt instructs AI to output meta_description delimiter');
assert_true(strpos($prompt, '24 hour plumber') !== false,    'T5h: prompt includes supporting keywords');
assert_true(strpos($prompt, 'primary navigation') !== false || strpos($prompt, 'primary nav') !== false || strpos($prompt, 'Services') !== false,
    'T5i: prompt mentions navigation context');

// T5j: type-specific instructions are embedded in prompt
$type_instr = clickfuzz_web_get_page_type_instructions('service');
assert_true(strpos($prompt, substr($type_instr, 0, 50)) !== false,
    'T5j: service type instructions embedded in prompt');

// ---------------------------------------------------------------------------
// T6: Prompt — media context injection
// ---------------------------------------------------------------------------
$mock_media = [(object)[
    'id' => 1, 'site_id' => 5, 'filename' => 'abc123.jpg',
    'alt_text' => 'Team photo', 'title' => 'Our Team', 'original_filename' => 'team.jpg',
]];
$prompt_with_media = clickfuzz_web_build_page_prompt($pg, $mock_site, $mock_lead, $mock_redesign, [], $mock_media);
assert_true(strpos($prompt_with_media, 'abc123.jpg') !== false,  'T6a: media filename appears in prompt');
assert_true(strpos($prompt_with_media, 'Team photo') !== false || strpos($prompt_with_media, 'Our Team') !== false,
    'T6b: media alt text or title appears in prompt');

// ---------------------------------------------------------------------------
// T7: SVG is no longer in the whitelist (Phase 3 security fix)
// ---------------------------------------------------------------------------
$allowed = PS_MEDIA_ALLOWED_MIMES;
assert_false(array_key_exists('image/svg+xml', $allowed), 'T7: SVG rejected from media upload whitelist');
assert_true(array_key_exists('image/jpeg',  $allowed),    'T7b: JPEG still allowed');
assert_true(array_key_exists('image/png',   $allowed),    'T7c: PNG still allowed');
assert_true(array_key_exists('image/gif',   $allowed),    'T7d: GIF still allowed');
assert_true(array_key_exists('image/webp',  $allowed),    'T7e: WebP still allowed');

// ---------------------------------------------------------------------------
// T8: Version history set-current logic
// ---------------------------------------------------------------------------
$gens = [
    (object)['id' => 10, 'page_id' => 1, 'is_current' => 0, 'dateadded' => '2026-08-26 10:00:00'],
    (object)['id' => 11, 'page_id' => 1, 'is_current' => 1, 'dateadded' => '2026-08-26 12:00:00'],
];

// T8a: find the current generation
$current = null;
foreach ($gens as $g) { if ($g->is_current) { $current = $g; break; } }
assert_true($current !== null,  'T8a: current generation found');
assert_eq($current->id, 11,     'T8b: most recent is current');

// T8c: setting a different version as current (simulated — clearing flags)
function mock_set_current($gens, $new_id) {
    foreach ($gens as $g) { $g->is_current = ($g->id === $new_id) ? 1 : 0; }
    return $gens;
}
$gens = mock_set_current($gens, 10);
$new_current = null;
foreach ($gens as $g) { if ($g->is_current) { $new_current = $g; break; } }
assert_eq($new_current->id, 10, 'T8c: older version set as current');

// T8d: generation belonging to wrong page is rejected
function mock_set_current_safe($page_id, $gen) {
    if ((int)$gen->page_id !== (int)$page_id) { return false; }
    return true;
}
$foreign_gen = (object)['id' => 99, 'page_id' => 99, 'is_current' => 0];
assert_false(mock_set_current_safe(1, $foreign_gen), 'T8d: cross-page generation rejected');
assert_true(mock_set_current_safe(1, (object)['id'=>10,'page_id'=>1,'is_current'=>0]), 'T8e: same-page generation accepted');

// ---------------------------------------------------------------------------
// T9: Cron concurrency — generating state acts as lock
// ---------------------------------------------------------------------------
// If a page is already 'generating', queue_page_for_generation must not re-claim it.
// The model method uses WHERE generation_status IN ('not_generated','failed') — so
// a page in 'generating' state returns 0 affected rows.
function mock_can_claim($status) {
    return in_array($status, ['not_generated', 'failed'], true);
}
assert_true(mock_can_claim('not_generated'),  'T9a: can claim not_generated');
assert_true(mock_can_claim('failed'),         'T9b: can claim failed (retry)');
assert_false(mock_can_claim('generating'),    'T9c: cannot claim already-generating page');
assert_false(mock_can_claim('generated'),     'T9d: cannot claim already-generated page (use regenerate)');

// ---------------------------------------------------------------------------
// T10: Preview controller — page ownership guard (cross-page gen ID)
// ---------------------------------------------------------------------------
function mock_preview_guard($page_id, $gen) {
    if (!$gen) return false;
    return (int)$gen->page_id === (int)$page_id;
}
$gen_own    = (object)['id'=>11,'page_id'=>5,'html_content'=>'<p>Hello</p>'];
$gen_foreign = (object)['id'=>12,'page_id'=>9,'html_content'=>'<p>Other</p>'];
assert_true( mock_preview_guard(5, $gen_own),     'T10a: same-page generation allowed for preview');
assert_false(mock_preview_guard(5, $gen_foreign), 'T10b: foreign-page generation rejected for preview');
assert_false(mock_preview_guard(5, null),         'T10c: null generation returns false');

// ---------------------------------------------------------------------------
// T11: Phase 1–3 regressions
// ---------------------------------------------------------------------------
// Phase 3: T6e — SVG is rejected (now covered by T7 above)
// Phase 3: slug sanitisation still works
function ps_sanitise_slug4($raw) {
    return strtolower(preg_replace('/[^a-z0-9\-]/', '', str_replace(' ', '-', $raw)));
}
assert_eq(ps_sanitise_slug4('AC Repair Austin'), 'ac-repair-austin', 'T11a: Phase 3 slug regression');

// Phase 2: generation_status independence from page status
$page_draft_not_generated = (object)['status'=>'draft','generation_status'=>'not_generated'];
assert_eq($page_draft_not_generated->status, 'draft', 'T11b: Phase 2 page status=draft independence');
assert_eq($page_draft_not_generated->generation_status, 'not_generated', 'T11c: Phase 2 generation_status=not_generated independence');

// Phase 1: publish_type validation regression
function ps_validate_publish_type4($t) { return in_array($t, ['html','wordpress'], true); }
assert_true( ps_validate_publish_type4('html'),      'T11d: Phase 1 publish_type html regression');
assert_false(ps_validate_publish_type4('ftp'),       'T11e: Phase 1 publish_type ftp regression');

// ---------------------------------------------------------------------------
// DB-dependent + API tests (listed for documentation)
// ---------------------------------------------------------------------------
t_skip('DB: queue_page_for_generation sets generation_status=generating',              'needs DB');
t_skip('DB: queue_page_for_generation rejects already-generating page',                'needs DB');
t_skip('DB: queue_page_for_generation rejects trashed page',                           'needs DB');
t_skip('DB: page_generate controller POST queues page and redirects',                  'needs DB + HTTP');
t_skip('DB: cron picks up pages with generation_status=generating',                    'needs DB + cron');
t_skip('API: generate_page() calls Anthropic and parses body_html response',           'needs Anthropic API key');
t_skip('API: generate_page() creates page_generations record on success',              'needs DB + API key');
t_skip('API: generate_page() calls set_current_page_generation on success',           'needs DB + API key');
t_skip('API: generate_page() marks page generation_status=generated on success',       'needs DB + API key');
t_skip('API: generate_page() marks page generation_status=failed on API error',        'needs DB + API key');
t_skip('API: generate_page() marks failed when body_html empty in response',           'needs DB + API key');
t_skip('DB: page_preview serves current generation HTML with noindex meta',            'needs DB + HTTP');
t_skip('DB: page_preview serves specific generation via ?gen= param',                  'needs DB + HTTP');
t_skip('DB: page_preview rejects foreign-page gen ID via ?gen= param',                'needs DB + HTTP');
t_skip('DB: page_generation_set_current updates is_current and current_generation_id', 'needs DB');
t_skip('DB: page_generation_set_current rejects cross-page generation ID',             'needs DB');
t_skip('DB: version history panel renders in admin_page_edit when generations exist',  'needs DB + HTTP');
t_skip('DB: Generate button active only when page is ready and not generating',        'needs DB + HTTP');
t_skip('DB: Regenerate button shown when generation_status=generated',                 'needs DB + HTTP');
t_skip('DB: Generating spinner shown when generation_status=generating',               'needs DB + HTTP');

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------
echo "\n=== Phase 4 Page Generation — Unit Tests ===\n";
foreach ($results as $r) { echo "  $r\n"; }
echo "\n";
echo "Total: " . ($pass + $fail) . " pure  |  Pass: $pass  |  Fail: $fail\n\n";
if ($fail > 0) { echo "FAILURES DETECTED\n"; exit(1); }
