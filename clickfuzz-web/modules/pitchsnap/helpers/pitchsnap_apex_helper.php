<?php
defined('BASEPATH') or exit('No direct script access allowed');

if (!defined('CFZ_APEX_API_BASE')) {
    define('CFZ_APEX_API_BASE', 'https://apex-api.clickfuzz.com');
}

/**
 * Provision apex redirect for a root domain via the ClickFuzz Apex API.
 *
 * Returns:
 *   ['success' => true,  'status' => 'pending']
 *   ['success' => false, 'error'  => string]
 */
function clickfuzz_web_apex_provision($domain)
{
    $result = _cfz_apex_request('POST', '/domains', ['domain' => $domain]);
    if (!$result['success']) {
        return $result;
    }
    return [
        'success' => true,
        'status'  => $result['data']['status'] ?? 'pending',
    ];
}

/**
 * Check apex redirect status for a root domain.
 *
 * Returns:
 *   ['success' => true,  'status' => 'pending'|'connected'|'failed']
 *   ['success' => false, 'error'  => string]
 */
function clickfuzz_web_apex_status_check($domain)
{
    $result = _cfz_apex_request('GET', '/domains/' . rawurlencode($domain));
    if (!$result['success']) {
        return $result;
    }
    return [
        'success' => true,
        'status'  => $result['data']['status'] ?? 'pending',
    ];
}

/**
 * Remove apex redirect for a root domain. Best-effort — caller ignores failures.
 *
 * Returns:
 *   ['success' => true]
 *   ['success' => false, 'error' => string]
 */
function clickfuzz_web_apex_remove($domain)
{
    $result = _cfz_apex_request('DELETE', '/domains/' . rawurlencode($domain));
    return $result['success'] ? ['success' => true] : $result;
}

/**
 * Shared cURL transport for the Apex API.
 * Domain is validated before inclusion in any URL.
 */
function _cfz_apex_request($method, $path, $body = null)
{
    // Validate domain embedded in path if it's a domain-level endpoint
    if (preg_match('#/domains/([^/]+)$#', $path, $m)) {
        $encoded = $m[1];
        $decoded = rawurldecode($encoded);
        if (!preg_match('/^[a-z0-9][a-z0-9\-\.]{0,251}[a-z0-9]$/i', $decoded) || strpos($decoded, '..') !== false) {
            return ['success' => false, 'error' => 'Invalid domain in request path.'];
        }
    }

    $api_token = get_option('pitchsnap_apex_api_token');
    if (!$api_token) {
        return ['success' => false, 'error' => 'Apex API token not configured.'];
    }

    $url = CFZ_APEX_API_BASE . $path;
    $ch  = curl_init($url);

    $headers = [
        'Authorization: Bearer ' . $api_token,
        'Accept: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];

    if ($body !== null) {
        $json      = json_encode($body);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = $json;
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'error' => 'cURL error: ' . $err];
    }
    if ($code === 401) {
        return ['success' => false, 'error' => 'Apex API authentication failed.'];
    }
    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'error' => 'Apex API returned HTTP ' . $code . '.'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Invalid JSON from Apex API.'];
    }
    if (empty($data['success'])) {
        return ['success' => false, 'error' => $data['message'] ?? 'Apex API error.'];
    }

    return ['success' => true, 'data' => $data];
}
