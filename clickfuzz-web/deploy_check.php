<?php
/**
 * PitchSnap deploy_check.php
 *
 * Compares SHA256 of every tracked local file against checksums.json
 * (recorded at last server sync). Prints a warning for any file that
 * has changed locally since the sync, so you know what needs to be
 * deployed to the server before going live.
 *
 * Usage:
 *   php deploy_check.php
 *
 * Run from the project root (same directory as checksums.json).
 */

$root         = __DIR__;
$checksum_file = $root . '/checksums.json';

if (!file_exists($checksum_file)) {
    echo "ERROR: checksums.json not found at {$checksum_file}\n";
    exit(1);
}

$checksums = json_decode(file_get_contents($checksum_file), true);
if (!is_array($checksums)) {
    echo "ERROR: checksums.json is not valid JSON.\n";
    exit(1);
}

$changed  = [];
$missing  = [];
$ok       = [];

foreach ($checksums as $rel_path => $expected_hash) {
    if (str_starts_with($rel_path, '_')) {
        // Skip metadata keys like _synced_at, _note
        continue;
    }
    $abs_path = $root . '/' . $rel_path;
    if (!file_exists($abs_path)) {
        $missing[] = $rel_path;
        continue;
    }
    $actual_hash = hash('sha256', file_get_contents($abs_path));
    if ($actual_hash !== $expected_hash) {
        $changed[] = $rel_path;
    } else {
        $ok[] = $rel_path;
    }
}

$total = count($ok) + count($changed) + count($missing);

echo "\n=== PitchSnap Deploy Check ===\n";
echo "Checked {$total} files against checksums.json\n\n";

if (empty($changed) && empty($missing)) {
    echo "All {$total} files match the last sync. Nothing to deploy.\n\n";
    exit(0);
}

if (!empty($changed)) {
    echo "CHANGED (local differs from last sync — deploy these):\n";
    foreach ($changed as $f) {
        echo "  [CHANGED]  {$f}\n";
    }
    echo "\n";
}

if (!empty($missing)) {
    echo "MISSING (file tracked in checksums but not found locally):\n";
    foreach ($missing as $f) {
        echo "  [MISSING]  {$f}\n";
    }
    echo "\n";
}

if (!empty($ok)) {
    $count_ok = count($ok);
    echo "OK ({$count_ok} files unchanged):\n";
    foreach ($ok as $f) {
        echo "  [OK]       {$f}\n";
    }
    echo "\n";
}

$count_issues = count($changed) + count($missing);
echo "Summary: {$count_issues} file(s) need attention, " . count($ok) . " file(s) unchanged.\n\n";
exit($count_issues > 0 ? 1 : 0);
