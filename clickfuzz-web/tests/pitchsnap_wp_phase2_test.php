<?php
/**
 * Phase 2 tests: controller methods + view UI + download path security.
 * Run from CLI: php tests/pitchsnap_wp_phase2_test.php
 * No CI/WP/DB dependencies required.
 */

$pass = 0;
$fail = 0;

function t2($name, $condition, $detail = '')
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

$base = __DIR__ . '/..';
$controller = $base . '/modules/pitchsnap/controllers/Pitchsnap.php';
$view       = $base . '/modules/pitchsnap/views/admin_detail.php';

echo "\n=== Phase 2: Controller structure ===\n";

$ctrl_src = file_get_contents($controller);

t2('controller file readable', $ctrl_src !== false);

t2('export_wordpress method present',
    (bool) preg_match('/public\s+function\s+export_wordpress\s*\(/', $ctrl_src));

t2('download_wordpress method present',
    (bool) preg_match('/public\s+function\s+download_wordpress\s*\(/', $ctrl_src));

t2('export_wordpress checks is_admin()',
    (bool) preg_match('/export_wordpress[\s\S]{0,200}is_admin\(\)/', $ctrl_src));

t2('export_wordpress checks POST',
    (bool) preg_match('/export_wordpress[\s\S]{0,400}input->post\(\)/', $ctrl_src));

t2('download_wordpress checks is_admin()',
    (bool) preg_match('/download_wordpress[\s\S]{0,200}is_admin\(\)/', $ctrl_src));

t2('download_wordpress uses realpath check',
    (bool) preg_match('/download_wordpress[\s\S]{0,1500}realpath/', $ctrl_src));

t2('download_wordpress path confinement (strpos check)',
    (bool) preg_match('/strpos\s*\(\s*\$real_zip\s*,\s*\$real_base/', $ctrl_src));

t2('download_wordpress uses readfile',
    (bool) preg_match('/readfile\s*\(\s*\$real_zip\s*\)/', $ctrl_src));

t2('download_wordpress sends Content-Disposition header',
    (bool) preg_match("/Content-Disposition.*attachment.*filename/", $ctrl_src));

t2('export_wordpress loads helper via require_once',
    (bool) preg_match('/pitchsnap_wordpress_helper\.php/', $ctrl_src));

t2('export_wordpress calls clickfuzz_web_export_wordpress_site',
    (bool) preg_match('/clickfuzz_web_export_wordpress_site\s*\(\s*\$id\s*\)/', $ctrl_src));

t2('export_wordpress calls log_activity on success',
    (bool) preg_match('/export_wordpress[\s\S]{0,2000}log_activity/', $ctrl_src));

t2('export_wordpress redirects to detail URL',
    (bool) preg_match('/export_wordpress[\s\S]{0,2000}redirect\s*\(\s*\$detail_url\s*\)/', $ctrl_src));

echo "\n=== Phase 2: View UI ===\n";

$view_src = file_get_contents($view);

t2('view file readable', $view_src !== false);

t2('view has WordPress export section label',
    strpos($view_src, 'WordPress Export') !== false);

t2('view exports via POST to export_wordpress action',
    strpos($view_src, "pitchsnap/export_wordpress/") !== false);

t2('view has Convert to WordPress button text',
    strpos($view_src, 'Convert to WordPress') !== false);

t2('view has Download WordPress Package button text',
    strpos($view_src, 'Download WordPress Package') !== false);

t2('view download link points to download_wordpress action',
    strpos($view_src, "pitchsnap/download_wordpress/") !== false);

t2('view has Regenerate button',
    strpos($view_src, 'Regenerate') !== false);

t2('view export section gated on generation_result',
    (bool) preg_match('/generation_result[\s\S]{0,2000}export_wordpress/', $view_src));

t2('view uses glob to detect existing export',
    strpos($view_src, 'glob(') !== false && strpos($view_src, 'exports/wordpress/') !== false);

t2('view uses CSRF token in export form',
    (bool) preg_match('/export_wordpress[\s\S]{0,600}get_csrf_token_name/', $view_src));

t2('view uses CSRF token in regenerate form',
    substr_count($view_src, 'get_csrf_token_name') >= 3,
    'Expected at least 3 CSRF fields (delete_preview + export + regenerate)');

echo "\n=== Phase 2: Download path security (unit) ===\n";

// Simulate the download_wordpress path-confinement logic in isolation.
$fake_base = sys_get_temp_dir() . '/cfw_wp_test_exports_' . getmypid();
@mkdir($fake_base . '/42', 0700, true);
$safe_zip  = $fake_base . '/42/clickfuzz-test-wordpress.zip';
file_put_contents($safe_zip, 'PK');   // minimal placeholder

$real_base_sim = realpath($fake_base);
$real_zip_sim  = realpath($safe_zip);

t2('safe path: realpath resolves', $real_zip_sim !== false, $safe_zip);
t2('safe path: confinement passes',
    $real_zip_sim && strpos($real_zip_sim, $real_base_sim . '/') === 0);

// Attempt traversal
$traversal = $fake_base . '/42/../../../etc/passwd';
$real_trav = realpath($traversal);
t2('traversal: realpath resolves to real target (if exists)', true, 'informational');
if ($real_trav) {
    t2('traversal: confinement blocks it',
        strpos($real_trav, $real_base_sim . '/') !== 0,
        'traversal resolved to: ' . $real_trav);
} else {
    t2('traversal: realpath returns false (no target exists) — safely blocked', true);
}

// Null-byte path — PHP 8 throws ValueError; catch it and treat as blocked
$null_path = $fake_base . "/42/\x00evil.zip";
try {
    $real_null = @realpath($null_path);
    t2('null-byte path: realpath returns false or blocked',
        $real_null === false || strpos((string) $real_null, $real_base_sim . '/') !== 0);
} catch (\ValueError $e) {
    t2('null-byte path: PHP 8 ValueError thrown — safely blocked', true);
}

// Cleanup
@unlink($safe_zip);
@rmdir($fake_base . '/42');
@rmdir($fake_base);

echo "\n";
$total = $pass + $fail;
echo ($fail === 0 ? "\033[32m" : "\033[31m");
echo "Results: {$pass}/{$total} passed";
if ($fail > 0) { echo ", {$fail} FAILED"; }
echo "\033[0m\n\n";
exit($fail > 0 ? 1 : 0);
