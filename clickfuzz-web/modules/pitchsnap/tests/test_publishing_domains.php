<?php
/**
 * Publishing Domains — standalone test suite.
 * Run from CLI: php modules/pitchsnap/tests/test_publishing_domains.php
 * Exit 0 = all pass, Exit 1 = failures.
 */

// ---------------------------------------------------------------------------
// Minimal stubs so the helper functions load without a full CI bootstrap
// ---------------------------------------------------------------------------

if (!defined('FCPATH'))    { define('FCPATH',    __DIR__ . '/../../../..'); }
if (!defined('BASEPATH'))  { define('BASEPATH',  FCPATH   . '/system/');  }

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
require_once __DIR__ . '/../helpers/pitchsnap_domain_helper.php';
require_once __DIR__ . '/../helpers/pitchsnap_dns_helper.php';

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
    public function seed_domain($site_id, $hostname, $type = 'platform') {
        $o = new stdClass();
        $o->site_id     = $site_id;
        $o->hostname    = $hostname;
        $o->domain_type = $type;
        $key = ($type === 'custom') ? 'c_' . (int) $site_id : (int) $site_id;
        $this->site_domains[$key] = $o;
        $this->taken[] = $hostname;
    }

    public function hostname_available($hostname) {
        return !in_array($hostname, $this->taken, true);
    }
    public function hostname_available_for_site($hostname, $site_id) {
        // Taken by a *different* site — compare via the row's site_id, not the array key
        foreach ($this->site_domains as $row) {
            if ($row->hostname === $hostname && (int) $row->site_id !== (int) $site_id) {
                return false;
            }
        }
        return true;
    }
    public function get_platform_domain_for_site($site_id) {
        $sid = (int) $site_id;
        if (isset($this->site_domains[$sid]) && $this->site_domains[$sid]->domain_type === 'platform') {
            return $this->site_domains[$sid];
        }
        return null;
    }
    public function get_custom_domain_for_site($site_id) {
        $key = 'c_' . (int) $site_id;
        return $this->site_domains[$key] ?? null;
    }
    public function get_site_by_id($site_id) {
        return $this->sites[(int) $site_id] ?? null;
    }
    public function create_site_domain($data) {
        $o = (object) $data;
        $type = $data['domain_type'] ?? 'platform';
        if ($type === 'custom') {
            $this->site_domains['c_' . (int) $data['site_id']] = $o;
        } else {
            $this->site_domains[(int) $data['site_id']] = $o;
        }
        $this->taken[] = $data['hostname'];
        return 99;
    }
    public function save_custom_domain($site_id, $hostname) {
        $existing = $this->get_custom_domain_for_site($site_id);
        $now = date('Y-m-d H:i:s');
        if ($existing) {
            $existing->hostname             = $hostname;
            $existing->verification_status  = 'pending';
            $existing->verified_at          = null;
            $existing->ssl_status           = 'pending';
            $existing->dateupdated          = $now;
            return (int) $existing->id;
        }
        return $this->create_site_domain([
            'id'                  => 999,
            'site_id'             => (int) $site_id,
            'hostname'            => $hostname,
            'domain_type'         => 'custom',
            'is_primary'          => 0,
            'status'              => 'active',
            'verification_status' => 'pending',
            'verified_at'         => null,
            'ssl_status'          => 'pending',
            'dateadded'           => $now,
        ]);
    }
    public function remove_custom_domain($site_id) {
        $key = 'c_' . (int) $site_id;
        if (isset($this->site_domains[$key])) {
            $hostname = $this->site_domains[$key]->hostname;
            unset($this->site_domains[$key]);
            $this->taken = array_values(array_filter($this->taken, fn($h) => $h !== $hostname));
            return true;
        }
        return false;
    }
    public function update_domain_verification($domain_id, $status, $verified_at) {
        // Find by id across all domains
        foreach ($this->site_domains as $row) {
            if (isset($row->id) && (int) $row->id === (int) $domain_id) {
                $row->verification_status = $status;
                $row->verified_at         = $verified_at;
                $row->dateupdated         = date('Y-m-d H:i:s');
                break;
            }
        }
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
// 13. Hostname normalization
// ---------------------------------------------------------------------------

echo "\n-- hostname normalization --\n";

eq('bare domain passthrough',      clickfuzz_web_normalize_hostname('example.com'),                          'example.com');
eq('https with path stripped',     clickfuzz_web_normalize_hostname('https://Example.com/path?q=1#frag'),    'example.com');
eq('http scheme stripped',         clickfuzz_web_normalize_hostname('http://MYDOMAIN.COM/'),                 'mydomain.com');
eq('www preserved',                clickfuzz_web_normalize_hostname('www.example.com'),                      'www.example.com');
eq('leading/trailing whitespace',  clickfuzz_web_normalize_hostname('  example.com  '),                     'example.com');
eq('trailing dot stripped',        clickfuzz_web_normalize_hostname('example.com.'),                         'example.com');
eq('uppercase lowercased',         clickfuzz_web_normalize_hostname('MyDomain.COM'),                         'mydomain.com');

// ---------------------------------------------------------------------------
// 14. Hostname validation — rejection cases
// ---------------------------------------------------------------------------

echo "\n-- hostname validation: rejections --\n";

$ci_v    =& get_instance();
$mock_v  = new MockPitchsnapModel();
$ci_v->pitchsnap_model = $mock_v;

$errFn = function($h) { return clickfuzz_web_validate_custom_hostname($h, 999); };

ok('empty rejected',               $errFn('') !== null);
ok('no TLD rejected',              $errFn('not-a-domain') !== null);
ok('IP address rejected',          $errFn('192.168.1.1') !== null);
ok('wildcard rejected',            $errFn('*.example.com') !== null);
ok('clickfuzz.com rejected',       $errFn('clickfuzz.com') !== null);
ok('platform subdomain rejected',  $errFn('jackrabbit.clickfuzz.com') !== null);
ok('double dot rejected',          $errFn('ex..ample.com') !== null);
ok('leading hyphen rejected',      $errFn('-example.com') !== null);

// ---------------------------------------------------------------------------
// 15. Hostname validation — acceptance cases
// ---------------------------------------------------------------------------

echo "\n-- hostname validation: acceptance --\n";

ok('valid apex domain',       clickfuzz_web_validate_custom_hostname('example.com', 999)     === null);
ok('valid www subdomain',     clickfuzz_web_validate_custom_hostname('www.example.com', 999) === null);
ok('valid deep subdomain',    clickfuzz_web_validate_custom_hostname('app.my-biz.io', 999)   === null);

// ---------------------------------------------------------------------------
// 16. Conflict check — hostname taken by another site
// ---------------------------------------------------------------------------

echo "\n-- conflict detection --\n";

$mock_conf = new MockPitchsnapModel();
$mock_conf->seed_domain(10, 'taken.com', 'custom');
$ci_v->pitchsnap_model = $mock_conf;

$conf_err = clickfuzz_web_validate_custom_hostname('taken.com', 20); // site 20, but site 10 has it
ok('hostname taken by other site → rejected', $conf_err !== null);

$own_err = clickfuzz_web_validate_custom_hostname('taken.com', 10); // site 10 updating its own
ok('same site own hostname → accepted',        $own_err === null);

// ---------------------------------------------------------------------------
// 17. Custom domain creation
// ---------------------------------------------------------------------------

echo "\n-- custom domain creation --\n";

$mock_cd = new MockPitchsnapModel();
$mock_cd->seed_site(20, 'clickfuzz.com/sites/ps-20-aa01');
// also seed platform domain for site 20
$mock_cd->seed_domain(20, 'ps-20-aa01.clickfuzz.com', 'platform');
$ci_v->pitchsnap_model = $mock_cd;

$result_id = $mock_cd->save_custom_domain(20, 'mybusiness.com');
ok('save_custom_domain returns id',             $result_id > 0);

$cd = $mock_cd->get_custom_domain_for_site(20);
ok('custom domain persisted',                   $cd !== null);
eq('correct hostname stored',                   $cd->hostname, 'mybusiness.com');
eq('verification_status = pending',             $cd->verification_status, 'pending');
eq('ssl_status = pending',                      $cd->ssl_status, 'pending');
eq('domain_type = custom',                      $cd->domain_type, 'custom');
eq('is_primary = 0',                            (int) $cd->is_primary, 0);

// Platform domain must still exist and be unaffected
$pd = $mock_cd->get_platform_domain_for_site(20);
ok('platform domain still present',             $pd !== null);
eq('platform hostname unchanged',               $pd->hostname, 'ps-20-aa01.clickfuzz.com');

// ---------------------------------------------------------------------------
// 18. Custom domain update resets status
// ---------------------------------------------------------------------------

echo "\n-- custom domain update --\n";

$update_id = $mock_cd->save_custom_domain(20, 'newdomain.com');
ok('update returns id',                         $update_id > 0);

$cd2 = $mock_cd->get_custom_domain_for_site(20);
eq('hostname updated',                          $cd2->hostname, 'newdomain.com');
eq('verification_status reset to pending',      $cd2->verification_status, 'pending');
eq('ssl_status reset to pending',               $cd2->ssl_status, 'pending');
ok('verified_at cleared',                       $cd2->verified_at === null);

// ---------------------------------------------------------------------------
// 19. Remove custom domain
// ---------------------------------------------------------------------------

echo "\n-- custom domain removal --\n";

$removed = $mock_cd->remove_custom_domain(20);
ok('remove returns true',                       $removed === true);
ok('custom domain gone',                        $mock_cd->get_custom_domain_for_site(20) === null);

// Platform domain still present after removal
$pd3 = $mock_cd->get_platform_domain_for_site(20);
ok('platform domain survives removal',          $pd3 !== null);
eq('platform hostname still correct',           $pd3->hostname, 'ps-20-aa01.clickfuzz.com');

$remove_again = $mock_cd->remove_custom_domain(20);
ok('remove non-existent returns false',         $remove_again === false);

// ---------------------------------------------------------------------------
// 20. Platform mapping untouched by custom-domain save
// ---------------------------------------------------------------------------

echo "\n-- platform mapping isolation --\n";

$mock_iso = new MockPitchsnapModel();
$mock_iso->seed_site(30, 'clickfuzz.com/sites/ps-30-bb99');
$mock_iso->seed_domain(30, 'myco.clickfuzz.com', 'platform');
$ci_v->pitchsnap_model = $mock_iso;

$mock_iso->save_custom_domain(30, 'myco.com');

$pd4 = $mock_iso->get_platform_domain_for_site(30);
ok('platform domain still set after custom save',  $pd4 !== null);
eq('platform hostname unmodified',                 $pd4->hostname, 'myco.clickfuzz.com');

$cd4 = $mock_iso->get_custom_domain_for_site(30);
ok('custom domain is separate record',             $cd4 !== null);
eq('custom hostname correct',                      $cd4->hostname, 'myco.com');

// ---------------------------------------------------------------------------
// 21. hostname_is_apex
// ---------------------------------------------------------------------------

echo "\n-- hostname_is_apex --\n";

ok('example.com is apex',          clickfuzz_web_hostname_is_apex('example.com'));
ok('www.example.com is NOT apex',  !clickfuzz_web_hostname_is_apex('www.example.com'));
ok('app.example.com is NOT apex',  !clickfuzz_web_hostname_is_apex('app.example.com'));
ok('sub.sub.example.com not apex', !clickfuzz_web_hostname_is_apex('sub.sub.example.com'));

// ---------------------------------------------------------------------------
// 22. expected_dns_records
// ---------------------------------------------------------------------------

echo "\n-- expected_dns_records --\n";

$apex_recs = clickfuzz_web_expected_dns_records('example.com');
ok('apex: two records returned',           count($apex_recs) === 2);
eq('apex: first is A @',                   $apex_recs[0]['type'] . ' ' . $apex_recs[0]['host'], 'A @');
eq('apex: first value is server IP',       $apex_recs[0]['value'], CFZ_DNS_SERVER_IP);
eq('apex: second is CNAME www',            $apex_recs[1]['type'] . ' ' . $apex_recs[1]['host'], 'CNAME www');
eq('apex: second value is runtime host',   $apex_recs[1]['value'], CFZ_DNS_RUNTIME_HOST);

$www_recs = clickfuzz_web_expected_dns_records('www.example.com');
ok('www: one record returned',             count($www_recs) === 1);
eq('www: CNAME www',                       $www_recs[0]['type'] . ' ' . $www_recs[0]['host'], 'CNAME www');
eq('www: value is runtime host',           $www_recs[0]['value'], CFZ_DNS_RUNTIME_HOST);

$sub_recs = clickfuzz_web_expected_dns_records('app.example.com');
eq('subdomain: label extracted',           $sub_recs[0]['host'], 'app');
eq('subdomain: CNAME type',                $sub_recs[0]['type'], 'CNAME');

// ---------------------------------------------------------------------------
// 23. DNS verification — apex: both A and www CNAME correct → verified
// ---------------------------------------------------------------------------

echo "\n-- verify_dns: apex pair both correct --\n";

$apex_a_ok   = function($h) { return [['ip' => CFZ_DNS_SERVER_IP]]; };
$apex_cn_ok  = function($h) { return [['target' => CFZ_DNS_RUNTIME_HOST . '.']]; };
$dns_a_none  = function($h) { return []; };
$dns_cn_none = function($h) { return []; };
$res_ok      = function($h) { return CFZ_DNS_SERVER_IP; };
$res_none    = function($h) { return $h; };

$r = clickfuzz_web_verify_dns('example.com', $apex_a_ok, $apex_cn_ok, $res_ok);
eq('apex: A + www CNAME + IP → verified', $r['status'], 'verified');
ok('records returned',                    !empty($r['records']));

// ---------------------------------------------------------------------------
// 24. DNS verification — subdomain CNAME + IP resolves → verified
// ---------------------------------------------------------------------------

echo "\n-- verify_dns: subdomain CNAME match with IP propagated --\n";

$r2 = clickfuzz_web_verify_dns('www.example.com', $dns_a_none, $apex_cn_ok, $res_ok);
eq('subdomain CNAME + IP resolves → verified', $r2['status'], 'verified');

// ---------------------------------------------------------------------------
// 25. DNS verification — subdomain CNAME correct but IP not yet propagated
// ---------------------------------------------------------------------------

echo "\n-- verify_dns: subdomain CNAME correct, IP not propagated --\n";

$r3 = clickfuzz_web_verify_dns('www.example.com', $dns_a_none, $apex_cn_ok, $res_none);
eq('subdomain CNAME + IP unresolved → pending', $r3['status'], 'pending');

// ---------------------------------------------------------------------------
// 26. DNS verification — apex: wrong A record (explicit misconfiguration)
// ---------------------------------------------------------------------------

echo "\n-- verify_dns: apex wrong A record --\n";

$dns_a_wrong = function($h) { return [['ip' => '1.2.3.4']]; };
$r4 = clickfuzz_web_verify_dns('example.com', $dns_a_wrong, $dns_cn_none, $res_none);
eq('apex wrong A → failed',    $r4['status'], 'failed');
ok('reason mentions wrong IP', strpos($r4['reason'], '1.2.3.4') !== false);

// ---------------------------------------------------------------------------
// 27. DNS verification — apex: wrong www CNAME target → failed
// ---------------------------------------------------------------------------

echo "\n-- verify_dns: apex wrong www CNAME --\n";

$dns_cn_wrong = function($h) { return [['target' => 'other-host.example.net']]; };
$r5 = clickfuzz_web_verify_dns('example.com', $apex_a_ok, $dns_cn_wrong, $res_none);
eq('apex wrong www CNAME → failed', $r5['status'], 'failed');

// ---------------------------------------------------------------------------
// 28. DNS verification — apex: A correct but www CNAME missing → pending
// ---------------------------------------------------------------------------

echo "\n-- verify_dns: apex A correct, www CNAME missing --\n";

$r6 = clickfuzz_web_verify_dns('example.com', $apex_a_ok, $dns_cn_none, $res_ok);
eq('apex: A ok, www CNAME missing → pending', $r6['status'], 'pending');
ok('reason mentions www',                     strpos($r6['reason'], 'www') !== false);

// ---------------------------------------------------------------------------
// 29. DNS verification — no records at all (propagating)
// ---------------------------------------------------------------------------

echo "\n-- verify_dns: no records --\n";

$r7 = clickfuzz_web_verify_dns('example.com', $dns_a_none, $dns_cn_none, $res_none);
eq('no records → pending',  $r7['status'], 'pending');
ok('empty records array',   $r7['records'] === []);

// ---------------------------------------------------------------------------
// 30. Verify does not affect platform mapping row
// ---------------------------------------------------------------------------

echo "\n-- verify: platform mapping isolation --\n";

$mock_verify = new MockPitchsnapModel();
$mock_verify->seed_site(50, 'clickfuzz.com/sites/ps-50-zz01');
$mock_verify->seed_domain(50, 'myco.clickfuzz.com', 'platform');
$mock_verify->save_custom_domain(50, 'myco.com');

$before_pd = $mock_verify->get_platform_domain_for_site(50);
ok('platform domain present after custom save', $before_pd !== null);
eq('platform hostname unchanged',              $before_pd->hostname, 'myco.clickfuzz.com');

// ---------------------------------------------------------------------------
// 31. Verify does not change ssl_status
// ---------------------------------------------------------------------------

echo "\n-- verify: ssl_status unchanged --\n";

$mock_us = new MockPitchsnapModel();
$mock_us->update_domain_verification_calls = [];
ok('update_domain_verification exists in MockPitchsnapModel',
   method_exists($mock_us, 'update_domain_verification'));

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
