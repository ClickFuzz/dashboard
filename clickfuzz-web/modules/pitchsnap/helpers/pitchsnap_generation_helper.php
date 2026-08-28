<?php
defined('BASEPATH') or exit('No direct script access allowed');

function clickfuzz_web_render_prompt($template, array $data)
{
    $map = [
        '{{business_name}}'       => $data['business_name']       ?? '',
        '{{website_url}}'         => $data['website_url']         ?? '',
        '{{email}}'               => $data['email']               ?? '',
        '{{phone}}'               => $data['phone']               ?? '',
        '{{role}}'                => $data['role']                ?? '',
        '{{company_size}}'        => $data['company_size']        ?? '',
        '{{desired_improvement}}' => $data['desired_improvement'] ?? '',
        '{{vertical}}'            => $data['vertical']            ?? '',
        '{{preview_token}}'       => $data['preview_token']       ?? '',
        '{{source_content}}'      => $data['source_content']      ?? '',
        '{{site_token}}'          => $data['site_token']          ?? '',
    ];

    return str_replace(array_keys($map), array_values($map), $template);
}

// ---------------------------------------------------------------------------
// HTML validation before deployment
// ---------------------------------------------------------------------------

/**
 * Basic sanity check on generated HTML before deploying it.
 * Returns an error string if invalid, null if valid.
 */
function clickfuzz_web_validate_html($html)
{
    if (!$html || strlen($html) < 500) {
        return 'Generated output is too short to be a valid website (' . strlen((string) $html) . ' bytes).';
    }
    if (stripos($html, '<html') === false) {
        return 'Generated output does not contain an HTML structure.';
    }
    if (stripos($html, 'pitchsnap/runtime.js') === false) {
        return 'ClickFuzz Web runtime script tag is missing from generated output.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// Preview deployment
// ---------------------------------------------------------------------------

/**
 * Replace hardcoded copyright years with the ClickFuzz Web dynamic year span.
 *
 * Only matches a year that immediately follows a copyright marker
 * (©, &copy;, (c), or the word Copyright). All other numeric years
 * in the document (founding dates, project dates, license numbers, etc.)
 * are untouched. Safe to call repeatedly — if the span is already present
 * there is no bare year after the marker, so the pattern does not match.
 */
function clickfuzz_web_normalize_copyright_year($html)
{
    return preg_replace(
        '/(©|&copy;|\(c\)|Copyright)(\s*)(20[0-9]{2})\b/i',
        '$1$2<span data-pitchsnap-current-year></span>',
        $html
    );
}

/**
 * Inject <meta name="robots" content="noindex,..."> into <head> if not already present.
 * Called by clickfuzz_web_deploy_preview() so every deployed page is protected
 * regardless of what the AI provider generated.
 */
function clickfuzz_web_inject_noindex($html)
{
    $meta = '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">';
    if (stripos($html, 'noindex') !== false) {
        return $html;
    }
    if (stripos($html, '</head>') !== false) {
        return str_ireplace('</head>', $meta . "\n</head>", $html);
    }
    if (stripos($html, '<head>') !== false) {
        return str_ireplace('<head>', "<head>\n" . $meta, $html);
    }
    return $meta . "\n" . $html;
}

/**
 * Write a generated HTML file to the public previews directory.
 * Each redesign gets its own isolated directory named by preview_token.
 *
 * Deterministically enforces preview protections on every deploy:
 *   - noindex meta tag injected into <head>
 *   - X-Robots-Tag header set via base directory .htaccess
 *
 * @param  string $preview_token  64-char hex token (validated here)
 * @param  string $html           Complete HTML content
 * @return array  ['success' => bool, 'url' => string|null, 'error' => string|null]
 */
function clickfuzz_web_deploy_preview($preview_token, $html)
{
    // Token must be exactly 64 hex chars — prevents path traversal
    if (!$preview_token || !preg_match('/^[a-f0-9]{64}$/', $preview_token)) {
        return ['success' => false, 'url' => null, 'error' => 'Invalid preview token format.'];
    }

    // Base previews directory — sibling of dashboard/, isolated from Perfex
    $base_dir = dirname(FCPATH) . '/previews';
    $dir      = $base_dir . '/' . $preview_token;
    $file     = $dir . '/index.html';

    if (!is_dir($base_dir)) {
        if (!mkdir($base_dir, 0755, true)) {
            return ['success' => false, 'url' => null, 'error' => 'Could not create previews directory.'];
        }
    }

    // Always write/overwrite base .htaccess so X-Robots-Tag header stays current
    // even if the directory was created by an older version.
    file_put_contents($base_dir . '/.htaccess', implode("\n", [
        '# ClickFuzz Web preview isolation',
        'php_flag engine off',
        'Options -ExecCGI -Indexes',
        'DirectoryIndex index.html',
        '<IfModule mod_headers.c>',
        '    Header always set X-Robots-Tag "noindex, nofollow, noarchive, nosnippet"',
        '</IfModule>',
    ]));

    // Create per-redesign directory
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            return ['success' => false, 'url' => null, 'error' => 'Could not create preview directory for token.'];
        }
    }

    // Deterministically inject noindex meta — never rely on the AI to include it
    $html = clickfuzz_web_inject_noindex($html);

    // Normalize hardcoded copyright years to the dynamic ClickFuzz Web span
    $html = clickfuzz_web_normalize_copyright_year($html);

    $written = file_put_contents($file, $html);
    if ($written === false) {
        return ['success' => false, 'url' => null, 'error' => 'Failed to write preview HTML file.'];
    }

    $url = 'https://clickfuzz.com/previews/' . $preview_token . '/';
    return ['success' => true, 'url' => $url, 'error' => null];
}

// ---------------------------------------------------------------------------
// Site helpers
// ---------------------------------------------------------------------------

/**
 * Build the canonical ClickFuzz Web runtime script tag.
 */
function clickfuzz_web_runtime_script_tag($preview_token, $site_token = '', $primary_color = '')
{
    $tag = '<script src="https://clickfuzz.com/dashboard/pitchsnap/runtime.js"'
         . ' data-redesign-token="' . htmlspecialchars($preview_token, ENT_QUOTES, 'UTF-8') . '"';
    if ($site_token) {
        $tag .= ' data-site-token="' . htmlspecialchars($site_token, ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($primary_color && preg_match('/^#[0-9A-Fa-f]{3}$|^#[0-9A-Fa-f]{6}$/', $primary_color)) {
        $tag .= ' data-primary-color="' . htmlspecialchars($primary_color, ENT_QUOTES, 'UTF-8') . '"';
    }
    $tag .= '></script>';
    return $tag;
}

/**
 * Generate a unique slug for a site based on website ID.
 */
function clickfuzz_web_generate_site_slug($website_id)
{
    return 'ps-' . (int) $website_id . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
}

/**
 * Get or create the permanent Site record for a website.
 * Idempotent — safe to call on every deploy.
 */
function clickfuzz_web_ensure_site($website_id, $lead_id = null)
{
    $CI =& get_instance();
    if (!isset($CI->pitchsnap_model)) {
        require_once(FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php');
        $CI->pitchsnap_model = new Pitchsnap_model();
    }

    $site = $CI->pitchsnap_model->get_site_by_website_id($website_id);
    if ($site) {
        return $site;
    }

    $slug       = clickfuzz_web_generate_site_slug($website_id);
    $site_token = bin2hex(random_bytes(32));
    $id = $CI->pitchsnap_model->create_site([
        'source_website_id' => (int) $website_id,
        'source_lead_id'    => $lead_id ? (int) $lead_id : null,
        'site_token'        => $site_token,
        'domain'            => 'clickfuzz.com/sites/' . $slug,
        'status'            => 'draft',
        'dateadded'         => date('Y-m-d H:i:s'),
    ]);

    return $id ? $CI->pitchsnap_model->get_site_by_id($id) : false;
}

/**
 * Convert a business name to a DNS-safe slug.
 * e.g. "Bob's Plumbing & Heating" → "bobs-plumbing-heating"
 */
function clickfuzz_web_slugify_business_name($name)
{
    // Transliterate accented/non-ASCII characters to ASCII equivalents
    // (e.g. Ü→U, é→e, č→c) before stripping
    $slug = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $name);
    if ($slug === false || $slug === '') {
        $slug = (string) $name;
    }
    $slug = mb_strtolower($slug, 'UTF-8');
    // Drop everything that is not a-z, 0-9, or whitespace (string is ASCII now)
    $slug = preg_replace('/[^a-z0-9\s]/', '', $slug);
    // Collapse whitespace to hyphens
    $slug = preg_replace('/\s+/', '-', trim($slug));
    // Collapse repeated hyphens
    $slug = preg_replace('/-+/', '-', $slug);
    // Trim leading/trailing hyphens
    $slug = trim($slug, '-');
    // DNS label max; leave room for a numeric suffix
    if (strlen($slug) > 50) {
        $slug = rtrim(substr($slug, 0, 50), '-');
    }
    return $slug;
}

/**
 * Generate a unique platform hostname for a site.
 * Prefers the lead's business/company name; falls back to the storage slug.
 * Does NOT write to the database — only checks for availability.
 */
function clickfuzz_web_generate_platform_hostname($site_id, $lead_id = null)
{
    $CI =& get_instance();
    if (!isset($CI->pitchsnap_model)) {
        require_once(FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php');
        $CI->pitchsnap_model = new Pitchsnap_model();
    }

    // Build base slug from lead business name
    $base = '';
    if ($lead_id) {
        $lead = $CI->db->where('id', (int) $lead_id)
                       ->get(db_prefix() . 'leads')
                       ->row();
        if ($lead) {
            $name = !empty($lead->company) ? $lead->company : (isset($lead->name) ? $lead->name : '');
            $base = clickfuzz_web_slugify_business_name($name);
        }
    }

    // Fallback: storage slug from domain field (clickfuzz.com/sites/{slug})
    if (!$base) {
        $site = $CI->pitchsnap_model->get_site_by_id($site_id);
        if ($site && !empty($site->domain)) {
            $parsed = ltrim(strstr($site->domain, '/sites/'), '/sites/');
            if ($parsed && preg_match('/^[a-z0-9\-]+$/', $parsed)) {
                $base = $parsed;
            }
        }
    }
    if (!$base) {
        $base = 'site-' . (int) $site_id;
    }

    // Find a unique hostname with deterministic numeric suffix
    $candidate = $base . '.clickfuzz.com';
    for ($n = 2; !$CI->pitchsnap_model->hostname_available($candidate) && $n <= 999; $n++) {
        $candidate = $base . '-' . $n . '.clickfuzz.com';
    }

    // Safety: if all suffixes exhausted, signal failure rather than return a collision
    if (!$CI->pitchsnap_model->hostname_available($candidate)) {
        return false;
    }

    return $candidate;
}

/**
 * Upload $content to {pitchsnap_publish_ftp_base}/{relative_path} on the hosted-sites server.
 * Credentials read from tbloptions (pitchsnap_publish_ftp_*).
 * Returns ['success'=>bool, 'error'=>string|null].
 */
function clickfuzz_web_remote_put(string $relative_path, string $content): array
{
    $host = get_option('pitchsnap_publish_ftp_host');
    $user = get_option('pitchsnap_publish_ftp_user');
    $pass = get_option('pitchsnap_publish_ftp_pass');
    $base = get_option('pitchsnap_publish_ftp_base');

    if (!$host || !$user || !$pass || !$base) {
        return ['success' => false, 'error' => 'Remote publish not configured (set pitchsnap_publish_ftp_* options).'];
    }

    // Base is an absolute server path (e.g. /home/xqsfhrlj/.../sites).
    // Keeping the leading '/' produces a double-slash after the host in the URL,
    // which tells cURL to treat the path as absolute (not relative to FTP root).
    // ftp:// with CURLUSESSL_ALL = Explicit FTPS (AUTH TLS on port 21).
    // ftps:// would be Implicit FTPS on port 990, which DirectAdmin does not use.
    $url = 'ftp://' . rtrim($host, '/') . '/' . rtrim($base, '/') . '/' . ltrim($relative_path, '/');

    $fp = fopen('php://temp', 'r+');
    if ($fp === false) {
        return ['success' => false, 'error' => 'Could not open in-memory stream for upload.'];
    }
    fwrite($fp, $content);
    rewind($fp);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL                     => $url,
        CURLOPT_USERPWD                 => $user . ':' . $pass,
        CURLOPT_UPLOAD                  => true,
        CURLOPT_INFILE                  => $fp,
        CURLOPT_INFILESIZE              => strlen($content),
        CURLOPT_FTP_CREATE_MISSING_DIRS => 2,   // CURLFTP_CREATE_DIR_RETRY
        CURLOPT_USE_SSL                 => CURLUSESSL_ALL,  // require TLS — no plaintext fallback
        CURLOPT_SSL_VERIFYPEER          => false,
        CURLOPT_SSL_VERIFYHOST          => 0,
        CURLOPT_FTPSSLAUTH              => CURLFTPAUTH_TLS,
        CURLOPT_FTP_USE_EPSV            => false,
        CURLOPT_TIMEOUT                 => 30,
        CURLOPT_RETURNTRANSFER          => true,
    ]);

    curl_exec($ch);
    $curl_err = curl_error($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);
    fclose($fp);

    if ($errno !== 0) {
        return ['success' => false, 'error' => 'FTPS upload failed: ' . ($curl_err ?: 'errno ' . $errno)];
    }

    return ['success' => true, 'error' => null];
}

/**
 * Publish a site: copy the latest preview HTML to sites/{slug}/index.html.
 * Returns ['success'=>bool, 'url'=>string|null, 'error'=>string|null].
 */
function clickfuzz_web_publish_site($site_id)
{
    $CI =& get_instance();
    if (!isset($CI->pitchsnap_model)) {
        require_once(FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php');
        $CI->pitchsnap_model = new Pitchsnap_model();
    }

    $site = $CI->pitchsnap_model->get_site_by_id($site_id);
    if (!$site) {
        return ['success' => false, 'url' => null, 'error' => 'Site not found.'];
    }

    $website = $CI->pitchsnap_model->get($site->source_website_id);
    if (!$website || !$website->preview_token) {
        return ['success' => false, 'url' => null, 'error' => 'No preview available for this site.'];
    }

    $preview_file = dirname(FCPATH) . '/previews/' . $website->preview_token . '/index.html';
    if (!file_exists($preview_file)) {
        return ['success' => false, 'url' => null, 'error' => 'Preview file not found on disk.'];
    }

    $html = file_get_contents($preview_file);
    if ($html === false) {
        return ['success' => false, 'url' => null, 'error' => 'Could not read preview file.'];
    }

    // Derive slug from domain field (clickfuzz.com/sites/{slug}).
    // substr() used for prefix removal — ltrim()'s second argument is a character mask,
    // not a string prefix, which would silently strip extra leading chars.
    $domain = $site->domain ?? '';
    $after  = strstr($domain, '/sites/');
    $slug   = ($after !== false) ? substr($after, strlen('/sites/')) : '';

    if (!$slug || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
        // Legacy record: domain was never set (created before slug system).
        // Generate, validate, and persist a slug before attempting FTPS.
        $slug = clickfuzz_web_generate_site_slug((int) $site->source_website_id);
        if (!$slug || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            return ['success' => false, 'url' => null, 'error' => 'Could not generate a valid site slug.'];
        }
        $CI->pitchsnap_model->update_site($site_id, ['domain' => 'clickfuzz.com/sites/' . $slug]);
        if ($CI->db->affected_rows() < 1) {
            return ['success' => false, 'url' => null, 'error' => 'Failed to persist generated site slug.'];
        }
    }

    // Remove noindex, strip all external scripts, re-inject canonical widget
    $html = preg_replace('/<meta[^>]+noindex[^>]*>/i', '', $html);

    // Normalize any remaining hardcoded copyright years (catches legacy previews)
    $html = clickfuzz_web_normalize_copyright_year($html);

    $_pub_color = '';
    if (preg_match('/<script[^>]+pitchsnap\/runtime\.js[^>]*\bdata-primary-color=["\']([^"\']{1,20})["\'][^>]*>/i', $html, $_m)) {
        $_c = trim($_m[1]);
        if (preg_match('/^#[0-9A-Fa-f]{3}$|^#[0-9A-Fa-f]{6}$/', $_c)) { $_pub_color = $_c; }
    }
    $html = preg_replace('/<script[^>]+\bsrc=[^>]*>\s*<\/script>/i', '', $html);
    $widget = clickfuzz_web_runtime_script_tag($website->preview_token, $site->site_token, $_pub_color);
    $html   = stripos($html, '</body>') !== false
        ? str_ireplace('</body>', $widget . "\n</body>", $html)
        : $html . "\n" . $widget;

    // Transfer to hosted-sites server if FTP credentials are configured;
    // fall back to local write (development / unconfigured environments).
    if (get_option('pitchsnap_publish_ftp_host')) {
        $upload = clickfuzz_web_remote_put($slug . '/index.html', $html);
        if (!$upload['success']) {
            return ['success' => false, 'url' => null, 'error' => $upload['error']];
        }
    } else {
        $sites_base = dirname(FCPATH) . '/sites';
        $site_dir   = $sites_base . '/' . $slug;

        if (!is_dir($sites_base) && !mkdir($sites_base, 0755, true)) {
            return ['success' => false, 'url' => null, 'error' => 'Could not create sites directory.'];
        }
        if (!is_dir($site_dir) && !mkdir($site_dir, 0755, true)) {
            return ['success' => false, 'url' => null, 'error' => 'Could not create site directory.'];
        }

        if (file_put_contents($site_dir . '/index.html', $html) === false) {
            return ['success' => false, 'url' => null, 'error' => 'Failed to write site HTML file.'];
        }
    }

    $CI->pitchsnap_model->update_site($site_id, [
        'status'      => 'published',
        'dateupdated' => date('Y-m-d H:i:s'),
    ]);

    // Resolve platform hostname: reuse existing mapping or generate a new one.
    // Use $website->lead_id (always populated on the redesign row) as the
    // authoritative source for the business name — more reliable than
    // $site->source_lead_id which can be NULL for older records.
    $domain_row = $CI->pitchsnap_model->get_platform_domain_for_site($site_id);
    if ($domain_row) {
        $hostname = $domain_row->hostname;
    } else {
        $hostname = clickfuzz_web_generate_platform_hostname($site_id, $website->lead_id ?? null);
        if (!$hostname) {
            return ['success' => false, 'url' => null, 'error' => 'Could not generate a unique platform hostname.'];
        }
        $CI->pitchsnap_model->create_site_domain([
            'site_id'     => (int) $site_id,
            'hostname'    => $hostname,
            'domain_type' => 'platform',
            'is_primary'  => 1,
            'status'      => 'active',
            'dateadded'   => date('Y-m-d H:i:s'),
        ]);
    }

    $pub_url = 'https://' . $hostname . '/';

    $pub_site = $CI->pitchsnap_model->get_site_by_id($site_id);
    if ($pub_site && !empty($pub_site->client_id)) {
        $client_email = clickfuzz_web_get_client_email((int) $pub_site->client_id);
        if ($client_email) {
            $client_row = $CI->db->select('company')->where('userid', (int) $pub_site->client_id)->get(db_prefix() . 'clients')->row();
            $company = $client_row ? $client_row->company : '';
            clickfuzz_web_send_mail('pitchsnap-website-published', $client_email, [
                '{company}'               => $company,
                '{production_website_url}' => $pub_url,
            ]);
        }
    }

    return ['success' => true, 'url' => $pub_url, 'error' => null];
}

// ---------------------------------------------------------------------------
// WordPress REST API publishing (Phase 1)
// ---------------------------------------------------------------------------

/**
 * Publish the generated HTML as a WordPress page via WP REST API.
 * Two-phase: (1) create/update the page, (2) assign as front page via Settings API.
 * Site is marked published ONLY after both phases succeed.
 *
 * Requires the WP Application Password user to have administrator-level
 * permissions (manage_options capability) for front-page assignment.
 *
 * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
 */
function clickfuzz_web_publish_site_wp($site_id)
{
    $CI =& get_instance();
    if (!isset($CI->pitchsnap_model)) {
        require_once(FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php');
        $CI->pitchsnap_model = new Pitchsnap_model();
    }

    $site = $CI->pitchsnap_model->get_site_by_id($site_id);
    if (!$site) {
        return ['success' => false, 'url' => null, 'error' => 'Site not found.'];
    }
    if (empty($site->wp_site_url)) {
        return ['success' => false, 'url' => null, 'error' => 'WordPress site URL not configured.'];
    }
    if (empty($site->wp_username) || empty($site->wp_app_password)) {
        return ['success' => false, 'url' => null, 'error' => 'WordPress credentials not configured.'];
    }

    $website = $CI->pitchsnap_model->get($site->source_website_id);
    if (!$website || !$website->preview_token) {
        return ['success' => false, 'url' => null, 'error' => 'No preview available for this site.'];
    }

    $preview_file = dirname(FCPATH) . '/previews/' . $website->preview_token . '/index.html';
    if (!file_exists($preview_file)) {
        return ['success' => false, 'url' => null, 'error' => 'Preview file not found on disk.'];
    }

    $html = file_get_contents($preview_file);
    if ($html === false) {
        return ['success' => false, 'url' => null, 'error' => 'Could not read preview file.'];
    }

    // Strip noindex and normalize copyright year for the live WP page
    $html = preg_replace('/<meta[^>]+noindex[^>]*>/i', '', $html);
    $html = clickfuzz_web_normalize_copyright_year($html);
    // Remove the pitchsnap preview widget — WP sites use their own session handling
    $html = preg_replace('/<script[^>]+\bsrc=[^>]*pitchsnap[^>]*>\s*<\/script>/i', '', $html);

    $wp_url      = rtrim($site->wp_site_url, '/');
    $credentials = base64_encode($site->wp_username . ':' . $site->wp_app_password);
    $headers     = [
        'Authorization: Basic ' . $credentials,
        'Content-Type: application/json',
        'User-Agent: ClickFuzz-Web/1.0',
    ];

    if (!function_exists('curl_init')) {
        return ['success' => false, 'url' => null, 'error' => 'cURL is not available on this server.'];
    }

    // Phase 1: create or update the WordPress page
    $page_data = json_encode([
        'title'   => ['raw' => 'Home'],
        'content' => ['raw' => $html],
        'status'  => 'publish',
        'slug'    => 'clickfuzz-homepage',
    ]);

    $existing_page_id = !empty($site->wp_page_id) ? (int) $site->wp_page_id : 0;
    $endpoint = $wp_url . '/wp-json/wp/v2/pages' . ($existing_page_id ? '/' . $existing_page_id : '');

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $page_data,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['success' => false, 'url' => null, 'error' => 'Connection error: ' . $curl_err];
    }

    $resp = json_decode($resp_body, true);

    if (!in_array($http_code, [200, 201]) || empty($resp['id'])) {
        $err_msg = isset($resp['message']) ? $resp['message'] : ('HTTP ' . $http_code);
        return ['success' => false, 'url' => null, 'error' => 'WordPress API error: ' . $err_msg];
    }

    $wp_page_id  = (int) $resp['id'];
    $wp_page_url = $resp['link'] ?? ($wp_url . '/');

    // Phase 2: set the published page as the WordPress front page
    // Requires the WP user to have administrator-level permissions (manage_options).
    $ch2 = curl_init($wp_url . '/wp-json/wp/v2/settings');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['show_on_front' => 'page', 'page_on_front' => $wp_page_id]),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $set_body = curl_exec($ch2);
    $set_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $set_err  = curl_error($ch2);
    curl_close($ch2);

    if ($set_err || !in_array($set_code, [200, 201])) {
        $set_detail = $set_err ?: ('HTTP ' . $set_code);
        return ['success' => false, 'url' => null, 'error' => 'Page published but front-page assignment failed: ' . $set_detail];
    }

    // Both phases succeeded — mark site as published
    $CI->pitchsnap_model->update_site($site_id, [
        'status'      => 'published',
        'wp_page_id'  => $wp_page_id,
        'dateupdated' => date('Y-m-d H:i:s'),
    ]);

    return ['success' => true, 'url' => $wp_page_url, 'error' => null];
}

// ---------------------------------------------------------------------------
// Post-publication history cleanup (Phase 1)
// ---------------------------------------------------------------------------

/**
 * After a site is published, delete all non-primary generation history for the lead:
 * preview files, conversations, and redesign records.
 * The primary (canonical) version is always preserved.
 * Returns count of deleted redesign records.
 */
function clickfuzz_web_cleanup_generation_history($lead_id)
{
    $CI =& get_instance();
    if (!isset($CI->pitchsnap_model)) {
        require_once(FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php');
        $CI->pitchsnap_model = new Pitchsnap_model();
    }

    $lead_id = (int) $lead_id;
    if (!$lead_id) { return 0; }

    $non_primary = $CI->pitchsnap_model->get_non_primary_redesigns_for_lead($lead_id);
    if (empty($non_primary)) { return 0; }

    $redesign_ids = array_map(function ($r) { return (int) $r->id; }, $non_primary);

    // Remove preview files from disk
    $base_dir  = dirname(FCPATH) . '/previews';
    $real_base = realpath($base_dir);
    foreach ($non_primary as $r) {
        if (empty($r->preview_token) || !preg_match('/^[a-f0-9]{64}$/', $r->preview_token)) { continue; }
        $preview_dir = $base_dir . '/' . $r->preview_token;
        if (!is_dir($preview_dir)) { continue; }
        $real_dir = realpath($preview_dir);
        if ($real_base && $real_dir && strpos($real_dir . '/', $real_base . '/') === 0) {
            foreach (@scandir($preview_dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                @unlink($preview_dir . '/' . $entry);
            }
            @rmdir($preview_dir);
        }
    }

    // Delete conversations for non-primary redesigns
    $conv_table = db_prefix() . 'pitchsnap_conversations';
    if ($CI->db->table_exists($conv_table)) {
        $CI->db->where_in('redesign_id', $redesign_ids)->delete($conv_table);
    }

    // Delete non-primary redesign records (double-guard with is_primary=0)
    $t = db_prefix() . 'pitchsnap_redesigns';
    $CI->db->where_in('id', $redesign_ids)->where('is_primary', 0)->delete($t);

    return count($redesign_ids);
}

// ---------------------------------------------------------------------------

function clickfuzz_web_copyright_year_instruction()
{
    return 'CURRENT COPYRIGHT YEAR
If the website includes a copyright year, do not hardcode a numeric year.
Use: <span data-pitchsnap-current-year></span>
Example: © <span data-pitchsnap-current-year></span> Company Name
ClickFuzz Web runtime automatically populates this with the visitor\'s current year.
Do not copy an outdated copyright year from the source website.
Do not use PHP.';
}

function clickfuzz_web_widget_instruction($preview_token)
{
    $src = 'https://clickfuzz.com/dashboard/pitchsnap/runtime.js';
    $tag = '<script src="' . $src . '" data-redesign-token="' . $preview_token . '" data-primary-color="#XXXXXX"></script>';
    return 'Install the required ClickFuzz Web chat widget and choose an appropriate widget color for the finished website.

Place this script tag immediately before </body> and copy the URL and data-redesign-token exactly as shown.
Replace #XXXXXX with a single hex color (#RRGGBB or #RGB) that visually fits the finished design:
— Prefer an established primary or accent brand color when ClickFuzz Web has supplied a reliable brand palette
— If the design uses a modernized shade of the established palette, use the prominent color actually used in the finished design
— If no reliable source brand palette exists, choose the primary or accent color you selected for the redesign
— Do not use an unrelated default blue simply because the widget supports it
— Choose a color that integrates naturally with the website and provides sufficient visual contrast for the chat button

' . $tag;
}

function clickfuzz_web_default_prompt()
{
    $prompt = <<<'PROMPT'
You are an expert web designer and developer specializing in local home service businesses. You have been hired to redesign a business's website to dramatically improve its visual quality and conversion rate.

Your output must be a COMPLETE, DEPLOYABLE HTML FILE — nothing else. No explanation, no commentary, no markdown code blocks. Start with <!DOCTYPE html> and end with </html>.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
BUSINESS FACTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Business Name: {{business_name}}
Industry: {{vertical}}
Website: {{website_url}}
Phone: {{phone}}
Email: {{email}}
Owner Goal: {{desired_improvement}}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
SOURCE WEBSITE CONTENT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
The following was extracted from {{website_url}}.
This is your ONLY permitted source of factual business claims.
Use the IMAGE URLS to reference real photos in your design.

{{source_content}}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ANTI-HALLUCINATION RULES — ABSOLUTE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Only include facts directly supported by the source content above.
Never invent or add:
— Customer reviews, testimonials, or star ratings (unless shown in source)
— Awards, badges, or industry recognition not mentioned
— Guarantees or warranties not explicitly stated
— Certifications or licenses not listed in source
— Years in business or founding date if not stated
— Service areas or cities not mentioned
— 24/7 or emergency service if not stated
— Team members not mentioned in source
— Pricing, financing, or discount offers
— Any factual business claim not in the source content

If a section would require invented content, omit that section entirely.
Fewer accurate sections is far better than more fabricated ones.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
DESIGN PHILOSOPHY
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

The single most important test: the business owner should look at this and think
"This is MY company, transformed." Not "this is a nice {{vertical}} template."

Typography:
— Choose 2 Google Fonts: a strong display/heading font and a clean readable body font
— Load from Google Fonts CDN
— Use real typographic hierarchy: large/bold hero headline, medium subheadings, comfortable body text
— Font sizes on mobile: hero headline minimum 2.2rem, headings 1.4rem, body 1rem

Visual Hierarchy:
— The phone number and primary CTA are the most important conversion elements — make them unmissable
— Phone number appears in the header (sticky or fixed), in the hero, and in a dedicated CTA strip
— Every section should clearly answer: what does this business do, why should I call them

Composition — each section must feel different from the others:
— Hero: bold full-width or high-contrast split layout. Headline + subhead + large CTA button + phone. No stock imagery placeholder boxes.
— Services: show their ACTUAL services from source content. A clean list, small grid, or icon+text layout. Avoid cookie-cutter rounded card grids.
— About / Why Us: asymmetric or narrative layout. Use the owner's real story and credentials from source. This section should feel personal, not corporate.
— CTA Strip: full-bleed section, single color background, short urgent headline, large phone number. Visually breaks up the page.
— Contact / Footer: all real contact info from source. Clean, organized.

Colors:
— Look for brand colors in the source content (color names, hex values, described scheme)
— If exact brand colors are not determinable, preserve the overall visual color character of the source website when possible. Use the existing site's imagery, logo, backgrounds, and visible styling as guidance. You may modernize or expand the palette with complementary colors, but do not unnecessarily replace the business's recognizable visual identity. If there is truly no reliable visual color direction, choose an appropriate professional palette for the business type ({{vertical}}). Do not default to navy/orange or generic tech colors unless the business identity clearly supports them.
— Use dark backgrounds for contrast where appropriate; avoid making everything the same background color
— All text must have sufficient contrast

Responsive (CSS only, no frameworks):
— Mobile-first: single column, large tap targets, tel: links on phone numbers
— One media query at 768px for desktop multi-column layouts
— Navigation collapses gracefully on mobile

What to AVOID:
— Generic AI aesthetic: identical rounded cards, SaaS gradients on every section, startup color palette
— Placeholder boxes for images (use real image URLs from source, or omit image sections entirely)
— Every section having the same background color and card layout
— Fake testimonials, star ratings, trust badges that aren't in the source

Real Imagery:
— Use the IMAGE URLS from the source content in <img> tags
— If no usable images, use CSS-only layouts for those sections
— Never use random placeholder services
— If source content shows "COMPANY LOGO: unavailable", do not fabricate a logo URL

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
TECHNICAL REQUIREMENTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
— Complete single-file HTML with all CSS in a <style> tag in <head>
— Google Fonts loaded via <link rel="preconnect"> + <link rel="stylesheet">
— No external CSS frameworks (no Bootstrap, Tailwind, etc.)
— Valid semantic HTML5 (header, nav, main, section, footer)
— All phone numbers wrapped in <a href="tel:..."> links
— All email addresses wrapped in <a href="mailto:..."> links

COPYRIGHT_YEAR_PLACEHOLDER

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PITCHSNAP WIDGET — MANDATORY — DO NOT OMIT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
WIDGET_INSTRUCTION_PLACEHOLDER

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
OUTPUT: The complete HTML file, starting now with <!DOCTYPE html>
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PROMPT;
    return str_replace(
        ['COPYRIGHT_YEAR_PLACEHOLDER', 'WIDGET_INSTRUCTION_PLACEHOLDER'],
        [clickfuzz_web_copyright_year_instruction(), clickfuzz_web_widget_instruction('{{preview_token}}')],
        $prompt
    );
}

function clickfuzz_web_manus_default_prompt()
{
    $prompt = <<<'PROMPT'
You are rebuilding the website for {{business_name}}, a {{vertical}} company.

PITCHSNAP STRUCTURED SOURCE DATA:
The following is structured information extracted from the source website, including classified images, discovered branding, business text, source provenance, and team/person associations where supported. Association confidence and evidence are included where applicable.

{{source_content}}

SOURCE WEBSITE — LIVE INSPECTION:
{{website_url}}

Visit this URL to verify details and supplement the structured data above. When the structured data and the live site agree, the structured data is authoritative. Do NOT remove Manus's own live-site inspection — use both sources.

TASK:
Create a professional, modern redesign that converts better than the original while keeping all real business information exactly as it appears on the source site.

WHAT TO PRESERVE FROM THE ORIGINAL SITE:
- Business name, logo, and brand colors
- All real services and service descriptions
- Phone number, email, and contact details
- Location and service area (only what is stated on the site)
- Real reviews and testimonials (only what is on the site)
- Real certifications, licenses, and credentials (only what is visible)
- Real team members and photos (only what is on the site)

IMPROVEMENT GOALS:
{{desired_improvement}}

DESIGN REQUIREMENTS:
- Clean, modern layout optimized for {{vertical}} businesses
- Mobile-responsive design
- Clear, prominent calls-to-action (phone number, contact form, quote request)
- Use the real business branding and color scheme
- Fast-loading, professional result

ANTI-HALLUCINATION RULES — CRITICAL — DO NOT VIOLATE:
Never invent or add anything not found on the original website, including:
- Customer reviews, testimonials, star ratings, or quotes
- Guarantees, warranties, or satisfaction pledges
- Certifications, licenses, accreditations, or professional memberships
- Awards, recognition, or industry ratings
- Years in business or founding dates
- Service areas, cities served, or coverage maps
- Emergency availability or 24/7 claims
- Pricing, estimates, or financing information
- Team member names, titles, or bios not on the original site
- Any factual claim not clearly visible on {{website_url}}

If information is missing from the original site, omit that section rather than inventing content.

COPYRIGHT_YEAR_PLACEHOLDER

PITCHSNAP WIDGET — NON-NEGOTIABLE REQUIREMENT:
WIDGET_INSTRUCTION_PLACEHOLDER

FINAL STEP:
When the redesign is complete, publish it as a live public website.
PROMPT;
    return str_replace(
        ['COPYRIGHT_YEAR_PLACEHOLDER', 'WIDGET_INSTRUCTION_PLACEHOLDER'],
        [clickfuzz_web_copyright_year_instruction(), clickfuzz_web_widget_instruction('{{preview_token}}')],
        $prompt
    );
}
