<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Provision a Cloudflare Custom Hostname (for SaaS).
 *
 * Returns:
 *   ['success' => true,  'cf_hostname_id' => string, 'cf_status' => 'connected'|'pending']
 *   ['success' => false, 'error' => string]
 */
function clickfuzz_web_cf_provision_hostname($hostname)
{
    $api_token = get_option('pitchsnap_cf_api_token');
    $zone_id   = get_option('pitchsnap_cf_zone_id');
    if (!$api_token || !$zone_id) {
        return ['success' => false, 'error' => 'Cloudflare API token or Zone ID not configured.'];
    }

    $url  = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id) . '/custom_hostnames';
    $body = json_encode([
        'hostname' => $hostname,
        'ssl'      => ['method' => 'http', 'type' => 'dv'],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'error' => 'cURL error: ' . $err];
    }
    $data = json_decode($raw, true);
    if (!$data || !isset($data['success'])) {
        return ['success' => false, 'error' => 'Invalid Cloudflare API response.'];
    }
    if (!$data['success']) {
        $msg  = isset($data['errors'][0]['message']) ? $data['errors'][0]['message'] : 'Cloudflare API error.';
        $code = isset($data['errors'][0]['code'])    ? (int) $data['errors'][0]['code'] : 0;
        // 1409 = hostname already exists — retrieve the existing record
        if ($code === 1409 || stripos($msg, 'already exists') !== false || stripos($msg, 'duplicate') !== false) {
            return _cfz_find_existing_hostname($hostname, $api_token, $zone_id);
        }
        return ['success' => false, 'error' => $msg];
    }

    return [
        'success'        => true,
        'cf_hostname_id' => $data['result']['id'] ?? null,
        'cf_status'      => _cfz_map_status($data['result']['status'] ?? 'pending'),
    ];
}

/**
 * Check the current Cloudflare status of a provisioned custom hostname.
 *
 * Returns: ['status' => 'connected'|'pending'|'failed']
 * On API error, returns 'pending' (leave for next poll, not a terminal failure).
 */
function clickfuzz_web_cf_check_hostname($cf_hostname_id)
{
    $api_token = get_option('pitchsnap_cf_api_token');
    $zone_id   = get_option('pitchsnap_cf_zone_id');
    if (!$api_token || !$zone_id) {
        return ['status' => 'pending'];
    }

    $url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id)
         . '/custom_hostnames/' . rawurlencode($cf_hostname_id);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) { return ['status' => 'pending']; }
    $data = json_decode($raw, true);
    if (!$data || !$data['success']) { return ['status' => 'pending']; }
    return ['status' => _cfz_map_status($data['result']['status'] ?? 'pending')];
}

/**
 * Delete a Cloudflare Custom Hostname by its CF ID.
 * Silently ignores failures (best-effort cleanup).
 */
function clickfuzz_web_cf_delete_hostname($cf_hostname_id)
{
    if (!$cf_hostname_id) { return; }
    $api_token = get_option('pitchsnap_cf_api_token');
    $zone_id   = get_option('pitchsnap_cf_zone_id');
    if (!$api_token || !$zone_id) { return; }

    $url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id)
         . '/custom_hostnames/' . rawurlencode($cf_hostname_id);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Derive the canonical Cloudflare custom hostname for a saved domain.
 *
 * MVP strategy: always provision www.{apex}.
 *   "eddmautofill.com"     → "www.eddmautofill.com"
 *   "www.eddmautofill.com" → "www.eddmautofill.com"
 *   "blog.eddmautofill.com" → null (non-www subdomains not supported for CF auto-provisioning)
 */
function clickfuzz_web_cf_canonical_hostname($hostname)
{
    $hostname = strtolower(trim((string) $hostname));
    if (substr_count($hostname, '.') === 1) {
        return 'www.' . $hostname;
    }
    if (strncmp($hostname, 'www.', 4) === 0) {
        return $hostname;
    }
    return null;
}

// ── Internal helpers ────────────────────────────────────────────────────────

function _cfz_map_status($cf_status)
{
    static $map = [
        'active'               => 'connected',
        'pending'              => 'pending',
        'pending_validation'   => 'pending',
        'pending_certificate'  => 'pending',
        'pending_blocked'      => 'pending',
        'pending_migration'    => 'pending',
        'pending_provisioned'  => 'pending',
        'provisioned'          => 'pending',
        'blocked'              => 'failed',
        'deleted'              => 'failed',
        'moved'                => 'failed',
        'error'                => 'failed',
    ];
    return $map[$cf_status] ?? 'pending';
}

function _cfz_find_existing_hostname($hostname, $api_token, $zone_id)
{
    $url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id)
         . '/custom_hostnames?hostname=' . rawurlencode($hostname);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($data && !empty($data['success']) && !empty($data['result'][0]['id'])) {
        $r = $data['result'][0];
        return [
            'success'        => true,
            'cf_hostname_id' => $r['id'],
            'cf_status'      => _cfz_map_status($r['status'] ?? 'pending'),
        ];
    }
    return ['success' => false, 'error' => 'Hostname already exists in Cloudflare but could not be retrieved.'];
}
