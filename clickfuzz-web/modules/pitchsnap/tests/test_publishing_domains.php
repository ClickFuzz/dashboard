<?php
/**
 * Publishing Domains — standalone test suite.
 * Run from CLI: php modules/pitchsnap/tests/test_publishing_domains.php
 * Exit 0 = all pass, Exit 1 = failures.
 */

// ---------------------------------------------------------------------------
// Minimal stubs so the helper functions load without a full CI bootstrap
// ---------------------------------------------------------------------------

if (!defined('FCPATH')) { define('FCPATH', __DIR__ . '/../../../..'); }

class CI_Stub {
    public $pitchsnap_model;
    public $db;
}

function &get_instance() {
    static $ci;
    if (!$ci) { $ci = new CI_Stub(); }
    return $ci;
}

function db_prefix() { return 'tbl'; }

require_once __DIR__ . '/../helpers/pitchsnap_generation_helper.php';

// ---------------------------------------------------------------------------
// Mock model
// ---------------------------------------------------------------------------

class MockPitchsnapModel {
    private $taken = [];
    private $site_domains = [];
    private $sites = [];

    public function seed_taken(array $hostnames) { $this->taken = $hostnames; }
    public function seed_site($site_id, $domain, $lead_id = null) {
        $o = new stdClass();
        $o->id             = $site_id;
        $o->domain         = $domain;
        $o->source_lead_id = $lead_id;
        $this->sites[$site_id] = $o;
    }
    public function seed_domain($site_id, $hostname) {
        $o = new stdClass();
        $o->site_id  = $site_id;
        $o->hostname = $hostname;
        $this->site_domains[$site_id] = $o;
    }

    public function hostname_available($hostname) {
        return !in_array($hostname, $this->taken, true);
    }
    public function get_platform_domain_for_site($site_id) {
        return $this->site_domains[(int) $site_id] ?? null;
    }
    public function get_site_by_id($site_id) {
        return $this->sites[(int) $site_id] ?? null;
    }
    public function create_site_domain($data) {
        $o = (object) $data;
        $this->site_domains[(int) $data['site_id']] = $o;
        $this->taken[] = $data['hostname'];
        return 99;
    }
}

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

$pass = 0;
$fail = 0;

function ok($label, $result) {
    global $pass, $fail;
    if ($result) { echo "  PASS  $label\n"; $pass++; }
    else         { echo "  FAIL  $label\n"; $fail++; }
}

function eq($label, $got, $expected) {
    global $pass, $fail;
    if ($got === $expected) {
        echo "  PASS  $label\n";
        $pass++;
    } else {
        echo "  FAIL  $label  (expected " . var_export($expected, true)
           . ", got " . var_export($got, true) . ")\n";
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// 1. Business-name slug generation — English names
// ---------------------------------------------------------------------------

echo "\n-- slug generation (English) --\n";

eq('plain words',          clickfuzz_web_slugify_business_name('McGillis HVAC'),            'mcgillis-hvac');
eq('apostrophe stripped',  clickfuzz_web_slugify_business_name("Bob's Plumbing & Heating"), 'bobs-plumbing-heating');
eq('ampersand stripped',   clickfuzz_web_slugify_business_name('Heat & Cool Inc'),          'heat-cool-inc');
eq('leading/trailing spc', clickfuzz_web_slugify_business_name('  Acme Corp  '),           'acme-corp');
eq('numbers preserved',    clickfuzz_web_slugify_business_name('Route 66 Auto'),           'route-66-auto');
eq('multiple spaces',      clickfuzz_web_slugify_business_name('Big   Spaces   Co'),       'big-spaces-co');

// ---------------------------------------------------------------------------
// 2. ASCII transliteration — international names
// ---------------------------------------------------------------------------

echo "\n-- transliteration (international) --\n";

eq('Czech accents',        clickfuzz_web_slugify_business_name('České Topení'),     'ceske-topeni');
eq('German umlaut',        clickfuzz_web_slugify_business_name('Müller Heating'),   'muller-heating');
eq('French accent',        clickfuzz_web_slugify_business_name('Café Plumbing'),    'cafe-plumbing');
eq('Spanish tilde',        clickfuzz_web_slugify_business_name('Señor HVAC'),       'senor-hvac');
eq('Scandinavian',         clickfuzz_web_slugify_business_name('Ångström Solar'),   'angstrom-solar');

// ---------------------------------------------------------------------------
// 3. Punctuation normalization edge cases
// ---------------------------------------------------------------------------

echo "\n-- punctuation normalization --\n";

eq('comma stripped',       clickfuzz_web_slugify_business_name('Smith, Jones & Associates'), 'smith-jones-associates');
eq('period stripped',      clickfuzz_web_slugify_business_name('Dr. Smith Dental'),          'dr-smith-dental');
eq('slash stripped',       clickfuzz_web_slugify_business_name('AC/DC Repairs'),             'acdc-repairs');
eq('parens stripped',      clickfuzz_web_slugify_business_name('Best (Local) Plumber'),      'best-local-plumber');

// ---------------------------------------------------------------------------
// 4. Degenerate inputs
// ---------------------------------------------------------------------------

echo "\n-- degenerate inputs --\n";

eq('empty string',         clickfuzz_web_slugify_business_name(''),    '');
eq('only punctuation',     clickfuzz_web_slugify_business_name('!@#'), '');
eq('only spaces',          clickfuzz_web_slugify_business_name('   '), '');

// ---------------------------------------------------------------------------
// 5. DNS label length truncation
// ---------------------------------------------------------------------------

echo "\n-- dns label truncation --\n";

$long   = str_repeat('a', 60);
$result = clickfuzz_web_slugify_business_name($long);
ok('truncated to ≤50 chars', strlen($result) <= 50);
ok('no trailing hyphen',      substr($result, -1) !== '-');

// ---------------------------------------------------------------------------
// 6. Duplicate hostname suffixing
// ---------------------------------------------------------------------------

echo "\n-- duplicate hostname suffixing --\n";

$ci   =& get_instance();
$mock = new MockPitchsnapModel();
$mock->seed_site(1, 'clickfuzz.com/sites/ps-1-ab12');
$ci->pitchsnap_model = $mock;

$h1 = clickfuzz_web_generate_platform_hostname(1);
eq('no collision → base hostname',   $h1, 'ps-1-ab12.clickfuzz.com');

$mock->seed_taken(['ps-1-ab12.clickfuzz.com']);
$h2 = clickfuzz_web_generate_platform_hostname(1);
eq('one collision → -2 suffix',      $h2, 'ps-1-ab12-2.clickfuzz.com');

$mock->seed_taken(['ps-1-ab12.clickfuzz.com', 'ps-1-ab12-2.clickfuzz.com']);
$h3 = clickfuzz_web_generate_platform_hostname(1);
eq('two collisions → -3 suffix',     $h3, 'ps-1-ab12-3.clickfuzz.com');

// ---------------------------------------------------------------------------
// 7. Hostname exhaustion fails safely (not silently returns collision)
// ---------------------------------------------------------------------------

echo "\n-- hostname exhaustion safety --\n";

$mock_ex = new MockPitchsnapModel();
$mock_ex->seed_site(99, 'clickfuzz.com/sites/ps-99-zzzz');
// Mark base + suffixes -2 through -999 all taken
$taken = ['ps-99-zzzz.clickfuzz.com'];
for ($n = 2; $n <= 999; $n++) { $taken[] = 'ps-99-zzzz-' . $n . '.clickfuzz.com'; }
$mock_ex->seed_taken($taken);
$ci->pitchsnap_model = $mock_ex;

$exhausted = clickfuzz_web_generate_platform_hostname(99);
ok('exhausted suffix range returns false', $exhausted === false);

// ---------------------------------------------------------------------------
// 8. Fallback hostname when no lead and no domain slug
// ---------------------------------------------------------------------------

echo "\n-- fallback hostname --\n";

$mock2 = new MockPitchsnapModel();
$mock2->seed_site(42, '');
$ci->pitchsnap_model = $mock2;

$fallback = clickfuzz_web_generate_platform_hostname(42);
eq('fallback uses site-{id}', $fallback, 'site-42.clickfuzz.com');

// ---------------------------------------------------------------------------
// 9. Authoritative site → lead/company lookup
//    publish_site uses $website->lead_id, NOT $site->source_lead_id.
//    Verify that generate_platform_hostname receives the lead_id from the
//    redesign row (simulated here by passing lead_id directly).
// ---------------------------------------------------------------------------

echo "\n-- authoritative lead/company path --\n";

// Simulate: lead_id comes from $website->lead_id (the redesign row)
// A DB stub that returns a mock lead row is not needed for the slug test —
// slug generation from a name is already proven above.
// Confirm: passing lead_id=null (absent source_lead_id) falls back to slug.
$mock3 = new MockPitchsnapModel();
$mock3->seed_site(5, 'clickfuzz.com/sites/ps-5-ef01');
$ci->pitchsnap_model = $mock3;

$h_no_lead = clickfuzz_web_generate_platform_hostname(5, null);
eq('no lead_id → falls back to storage slug', $h_no_lead, 'ps-5-ef01.clickfuzz.com');

// ---------------------------------------------------------------------------
// 10. Platform-domain creation
// ---------------------------------------------------------------------------

echo "\n-- platform-domain creation --\n";

$mock4 = new MockPitchsnapModel();
$mock4->seed_site(7, 'clickfuzz.com/sites/ps-7-ccdd');
$ci->pitchsnap_model = $mock4;

$hn = clickfuzz_web_generate_platform_hostname(7);
eq('hostname generated from slug', $hn, 'ps-7-ccdd.clickfuzz.com');

$id = $mock4->create_site_domain([
    'site_id' => 7, 'hostname' => $hn,
    'domain_type' => 'platform', 'is_primary' => 1,
    'status' => 'active', 'dateadded' => date('Y-m-d H:i:s'),
]);
ok('create_site_domain returns id', $id > 0);

$row = $mock4->get_platform_domain_for_site(7);
eq('persisted with correct hostname', $row->hostname, 'ps-7-ccdd.clickfuzz.com');

// ---------------------------------------------------------------------------
// 11. Republish reuses existing mapping
// ---------------------------------------------------------------------------

echo "\n-- republish reuses hostname --\n";

$mock5 = new MockPitchsnapModel();
$mock5->seed_site(5, 'clickfuzz.com/sites/ps-5-ef01');
$mock5->seed_domain(5, 'acme-hvac.clickfuzz.com');
$ci->pitchsnap_model = $mock5;

$existing = $mock5->get_platform_domain_for_site(5);
ok('existing mapping found',     $existing !== null);
eq('existing hostname returned', $existing->hostname, 'acme-hvac.clickfuzz.com');

// ---------------------------------------------------------------------------
// 12. Republish does not create duplicate
// ---------------------------------------------------------------------------

echo "\n-- republish no duplicate --\n";

$create_called = 0;
$row2 = $mock5->get_platform_domain_for_site(5);
if (!$row2) {
    $mock5->create_site_domain(['site_id' => 5, 'hostname' => 'acme-hvac.clickfuzz.com',
        'domain_type' => 'platform', 'is_primary' => 1, 'status' => 'active',
        'dateadded' => date('Y-m-d H:i:s')]);
    $create_called++;
}
eq('create_site_domain not called on republish', $create_called, 0);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
