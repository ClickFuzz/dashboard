<?php
/**
 * ClickFuzz hosted-site runtime — sites.clickfuzz.com
 * Routes: HTTP_HOST → domain mapping → storage slug → static file
 */

ini_set('display_errors', '0');
error_reporting(0);

// ── Constants ─────────────────────────────────────────────────────────────────

define('SITES_BASE_DIR',   '/home/clgorman/domains/clickfuzz.com/public_html/sites/');
define('APP_CONFIG_PATH',  '/home/clgorman/domains/clickfuzz.com/public_html/dashboard/application/config/app-config.php');
define('DB_DOMAINS_TABLE', 'tblpitchsnap_site_domains');
define('DB_SITES_TABLE',   'tblpitchsnap_sites');
define('RUNTIME_OWN_HOST', 'sites.clickfuzz.com');

// ── Error helper — never leaks internals ──────────────────────────────────────

function abort(int $code): void
{
    static $sent = false;
    if ($sent) { exit; }
    $sent = true;
    $labels = [
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
    ];
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $code . ' ' . ($labels[$code] ?? 'Error') . "\n";
    exit;
}

// ── 1. Hostname normalization and validation ───────────────────────────────────

$raw_host = $_SERVER['HTTP_HOST'] ?? '';
$host     = strtolower($raw_host);
$host     = (string) preg_replace('/:\d+$/', '', $host);  // strip :port
$host     = rtrim($host, '.');                             // strip trailing dot

if ($host === '') { abort(400); }

// The runtime's own infrastructure hostname has no customer mapping unless the
// Cloudflare Worker is proxying a custom-domain request through it.
if ($host === RUNTIME_OWN_HOST) {
    // Only trust X-ClickFuzz-Host when the actual incoming host is sites.clickfuzz.com
    // (i.e. the Worker path). Apply identical normalization to the override value.
    $override = strtolower($_SERVER['HTTP_X_CLICKFUZZ_HOST'] ?? '');
    $override = (string) preg_replace('/:\d+$/', '', $override);
    $override = rtrim($override, '.');
    if ($override === '') { abort(404); }
    $host = $override;
    // RFC validation below still runs on $host (now the override value).
}

// Validate hostname structure: RFC-compliant labels separated by dots
if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
    abort(400);
}

// ── 2. DB connection ──────────────────────────────────────────────────────────

$cfg = @file_get_contents(APP_CONFIG_PATH);
if ($cfg === false) { abort(500); }

function cfg_const(string $raw, string $name): ?string
{
    preg_match("/define\('" . preg_quote($name, '/') . "',\s*'([^']*)'\)/", $raw, $m);
    return isset($m[1]) ? $m[1] : null;
}

$db_host = cfg_const($cfg, 'APP_DB_HOSTNAME');
$db_user = cfg_const($cfg, 'APP_DB_USERNAME');
$db_pass = cfg_const($cfg, 'APP_DB_PASSWORD') ?? '';
$db_name = cfg_const($cfg, 'APP_DB_NAME');

if (!$db_host || !$db_user || !$db_name) { abort(500); }

$db = @new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($db->connect_error) { abort(500); }
$db->set_charset('utf8mb4');

// ── 3. Domain mapping lookup ──────────────────────────────────────────────────

$stmt = $db->prepare(
    'SELECT site_id FROM ' . DB_DOMAINS_TABLE .
    ' WHERE hostname = ? AND status = ? LIMIT 1'
);
if (!$stmt) { $db->close(); abort(500); }

$active = 'active';
$stmt->bind_param('ss', $host, $active);
$stmt->execute();
$stmt->bind_result($site_id);
$mapped = $stmt->fetch();
$stmt->close();

// www alias: if exact match fails for www.{apex}, retry with the stored apex hostname.
// One normalized apex row covers both eddmautofill.com (apex) and www.eddmautofill.com (Worker path).
if (!$mapped && strncmp($host, 'www.', 4) === 0) {
    $apex = substr($host, 4);
    if (preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $apex)) {
        $site_id  = null;
        $stmt_a = $db->prepare(
            'SELECT site_id FROM ' . DB_DOMAINS_TABLE .
            ' WHERE hostname = ? AND status = ? LIMIT 1'
        );
        if ($stmt_a) {
            $stmt_a->bind_param('ss', $apex, $active);
            $stmt_a->execute();
            $stmt_a->bind_result($site_id);
            $mapped = $stmt_a->fetch();
            $stmt_a->close();
        }
    }
}

if (!$mapped || !$site_id) { $db->close(); abort(404); }

// ── 4. Site row + storage slug extraction ─────────────────────────────────────

$stmt2 = $db->prepare(
    'SELECT domain FROM ' . DB_SITES_TABLE . ' WHERE id = ? LIMIT 1'
);
if (!$stmt2) { $db->close(); abort(500); }

$stmt2->bind_param('i', $site_id);
$stmt2->execute();
$stmt2->bind_result($site_domain);
$found_site = $stmt2->fetch();
$stmt2->close();
$db->close();

if (!$found_site || !$site_domain) { abort(404); }

// Extract slug from "clickfuzz.com/sites/{slug}"
// Regex validates slug character set simultaneously
if (!preg_match('|/sites/([a-z0-9][a-z0-9\-]*[a-z0-9])$|', $site_domain, $slug_m)) {
    abort(404);
}
$slug = $slug_m[1];

// ── 5. Path resolution ────────────────────────────────────────────────────────

// Reject null bytes immediately
$raw_uri = $_SERVER['REQUEST_URI'] ?? '/';
if (strpos($raw_uri, "\0") !== false) { abort(400); }

// Block encoded traversal in raw (pre-decode) URI
if (preg_match('/%2e%2e|%252e|%252f|\.\./i', $raw_uri)) { abort(403); }

// Parse and decode URI path once
$uri = parse_url($raw_uri, PHP_URL_PATH);
if ($uri === false || $uri === null) { abort(400); }

// Reject traversal in decoded path
if (preg_match('#(?:^|/)\.\.(?:/|$)#', $uri)) { abort(403); }

// Build site root with verified real path (realpath resolves symlinks)
$site_root_raw = SITES_BASE_DIR . $slug;
$real_root     = realpath($site_root_raw);
if ($real_root === false) { abort(404); }  // site directory not yet published
$real_root .= '/';                          // trailing slash required for containment check

// Build candidate file path
$rel = ltrim($uri, '/');

if ($rel === '' || substr($uri, -1) === '/') {
    // Directory-style path → index.html
    $candidate = $real_root . $rel . 'index.html';
} else {
    $candidate = $real_root . $rel;
}

$real_file = realpath($candidate);

// Extensionless path: try as directory index
if ($real_file === false && pathinfo($candidate, PATHINFO_EXTENSION) === '') {
    $real_file = realpath($candidate . '/index.html');
}

if ($real_file === false) { abort(404); }

// Path containment: resolved path must begin with the site root
// Because realpath() follows symlinks, this also blocks symlink escapes
if (strpos($real_file, $real_root) !== 0) { abort(403); }

// Must be a regular file (not a directory or device)
if (!is_file($real_file)) { abort(404); }

// Block server-side executable types — never serve raw script source
$ext = strtolower(pathinfo($real_file, PATHINFO_EXTENSION));
static $blocked_ext = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
                        'pl', 'py', 'rb', 'sh', 'cgi', 'asp', 'aspx'];
if (in_array($ext, $blocked_ext, true)) { abort(403); }

// ── 6. MIME type ──────────────────────────────────────────────────────────────

static $mime_map = [
    'html'  => 'text/html; charset=utf-8',
    'htm'   => 'text/html; charset=utf-8',
    'css'   => 'text/css; charset=utf-8',
    'js'    => 'application/javascript; charset=utf-8',
    'json'  => 'application/json; charset=utf-8',
    'svg'   => 'image/svg+xml',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'webp'  => 'image/webp',
    'gif'   => 'image/gif',
    'ico'   => 'image/x-icon',
    'txt'   => 'text/plain; charset=utf-8',
    'xml'   => 'application/xml; charset=utf-8',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf'   => 'font/ttf',
    'otf'   => 'font/otf',
    'mp4'   => 'video/mp4',
    'webm'  => 'video/webm',
    'pdf'   => 'application/pdf',
];

$mime = isset($mime_map[$ext]) ? $mime_map[$ext] : 'application/octet-stream';

// ── 7. Cache headers ──────────────────────────────────────────────────────────

if (in_array($ext, ['html', 'htm'], true)) {
    header('Cache-Control: no-cache, must-revalidate');
} else {
    header('Cache-Control: public, max-age=3600');
}

// ── 8. Serve ──────────────────────────────────────────────────────────────────

header('Content-Type: '   . $mime);
header('Content-Length: ' . (string) filesize($real_file));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

readfile($real_file);
