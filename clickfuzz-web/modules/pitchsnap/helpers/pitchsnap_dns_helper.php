<?php
defined('BASEPATH') or exit('No direct script access allowed');

if (!defined('CFZ_DNS_SERVER_IP'))    { define('CFZ_DNS_SERVER_IP',    '104.152.168.38'); }
if (!defined('CFZ_DNS_RUNTIME_HOST')) { define('CFZ_DNS_RUNTIME_HOST', 'customers.clickfuzz.com'); }
if (!defined('CFZ_DNS_FALLBACK_HOST')) { define('CFZ_DNS_FALLBACK_HOST', 'sites.clickfuzz.com'); }

/**
 * True when hostname is an apex/root domain (exactly one dot: example.com).
 * RFC 1034 forbids CNAME at the zone apex; apex domains must use A records.
 */
function clickfuzz_web_hostname_is_apex($hostname)
{
    return substr_count((string) $hostname, '.') === 1;
}

/**
 * Return the DNS records the customer needs to create.
 *
 * For an apex domain both records are REQUIRED:
 *   A     @   → 104.152.168.38         (root domain)
 *   CNAME www → customers.clickfuzz.com (www via Cloudflare for SaaS — required)
 *
 * For a subdomain one record is required:
 *   CNAME {label} → customers.clickfuzz.com
 *
 * Each record: ['type', 'host', 'value', 'note']
 * 'host' is the relative label used in most DNS UIs (@ for apex).
 */
function clickfuzz_web_expected_dns_records($hostname)
{
    if (clickfuzz_web_hostname_is_apex($hostname)) {
        return [
            ['type' => 'A',     'host' => '@',   'value' => CFZ_DNS_SERVER_IP,    'note' => 'Root domain → ClickFuzz (required)'],
            ['type' => 'CNAME', 'host' => 'www', 'value' => CFZ_DNS_RUNTIME_HOST, 'note' => 'www → ClickFuzz (required)'],
        ];
    }

    // Subdomain (www.example.com, app.example.com, etc.)
    $parts = explode('.', $hostname);
    $label = $parts[0];
    return [
        ['type' => 'CNAME', 'host' => $label, 'value' => CFZ_DNS_RUNTIME_HOST, 'note' => 'Subdomain → ClickFuzz (required)'],
    ];
}

/**
 * Verify that a custom hostname resolves correctly to ClickFuzz infrastructure.
 *
 * For APEX hostnames (e.g. example.com), BOTH of these must be correct:
 *   1. example.com  → A record → 104.152.168.38
 *   2. www.example.com → CNAME → customers.clickfuzz.com (and IP chain resolves)
 * Status is 'verified' only when both pass.
 *
 * For subdomain hostnames (e.g. www.example.com), only that hostname is checked.
 *
 * Injectable callables allow deterministic unit testing without real DNS:
 *   $a_lookup($host)     → array of {'ip': '...'} records
 *   $cname_lookup($host) → array of {'target': '...'} records
 *   $resolver($host)     → resolved IP string, or original hostname on failure
 *
 * Returns:
 *   status  — 'verified' | 'pending' | 'failed'
 *   reason  — human-readable explanation
 *   records — sanitized array of observed DNS records
 */
function clickfuzz_web_verify_dns($hostname, $a_lookup = null, $cname_lookup = null, $resolver = null)
{
    $hostname = strtolower(trim((string) $hostname));
    if ($hostname === '') {
        return ['status' => 'failed', 'reason' => 'Hostname is empty.', 'records' => []];
    }

    if (!$a_lookup)     { $a_lookup     = function($h) { return @dns_get_record($h, DNS_A) ?: []; }; }
    if (!$cname_lookup) { $cname_lookup = function($h) { return @dns_get_record($h, DNS_CNAME) ?: []; }; }
    if (!$resolver)     { $resolver     = function($h) { return @gethostbyname($h); }; }

    if (clickfuzz_web_hostname_is_apex($hostname)) {
        return _cfz_verify_dns_apex_pair($hostname, $a_lookup, $cname_lookup, $resolver);
    }

    return _cfz_verify_dns_single($hostname, $a_lookup, $cname_lookup, $resolver);
}

/**
 * Apex-pair check: both A @ and CNAME www must be correct to reach 'verified'.
 */
function _cfz_verify_dns_apex_pair($apex, $a_lookup, $cname_lookup, $resolver)
{
    $www       = 'www.' . $apex;
    $target_ip = CFZ_DNS_SERVER_IP;
    $target_cn = CFZ_DNS_RUNTIME_HOST;
    $observed  = [];

    // --- Apex A record ---
    $apex_ok          = false;
    $apex_has_wrong   = false;
    $apex_wrong_ip    = null;
    foreach ((array) $a_lookup($apex) as $r) {
        $ip = $r['ip'] ?? '';
        if ($ip === '') continue;
        $observed[] = ['type' => 'A', 'host' => '@', 'value' => $ip];
        if ($ip === $target_ip) { $apex_ok = true; }
        else { $apex_has_wrong = true; $apex_wrong_ip = $ip; }
    }

    // --- www CNAME record ---
    $www_cname_ok     = false;
    $www_ip_ok        = false;
    $www_has_wrong    = false;
    $www_wrong_target = null;
    foreach ((array) $cname_lookup($www) as $r) {
        $target = strtolower(rtrim($r['target'] ?? '', '.'));
        if ($target === '') continue;
        $observed[] = ['type' => 'CNAME', 'host' => 'www', 'value' => $target];
        if ($target === $target_cn) {
            $www_cname_ok = true;
            if ($resolver($www) === $target_ip) { $www_ip_ok = true; }
        } else {
            $www_has_wrong = true;
            $www_wrong_target = $target;
        }
    }

    // Both fully correct
    if ($apex_ok && $www_cname_ok && $www_ip_ok) {
        return [
            'status'  => 'verified',
            'reason'  => 'Both A and CNAME records are correct and resolving to ClickFuzz.',
            'records' => $observed,
        ];
    }

    // Apex A correct + www CNAME correct but IP chain not yet propagated
    if ($apex_ok && $www_cname_ok && !$www_ip_ok) {
        return [
            'status'  => 'pending',
            'reason'  => 'A record is correct. www CNAME is set but IP has not fully propagated yet.',
            'records' => $observed,
        ];
    }

    // Explicit misconfiguration — wrong target on either record
    if ($apex_has_wrong && !$apex_ok) {
        return [
            'status'  => 'failed',
            'reason'  => "A record for {$apex} points to {$apex_wrong_ip}, not {$target_ip}.",
            'records' => $observed,
        ];
    }
    if ($www_has_wrong && !$www_cname_ok) {
        return [
            'status'  => 'failed',
            'reason'  => "CNAME for {$www} points to {$www_wrong_target}, not {$target_cn}.",
            'records' => $observed,
        ];
    }

    // Apex A correct but www CNAME not found yet
    if ($apex_ok && !$www_cname_ok) {
        return [
            'status'  => 'pending',
            'reason'  => "A record for {$apex} is correct. www CNAME record not yet found — add CNAME www → {$target_cn}.",
            'records' => $observed,
        ];
    }

    // Nothing found yet
    return [
        'status'  => 'pending',
        'reason'  => 'DNS records not yet found. This may still be propagating.',
        'records' => $observed,
    ];
}

/**
 * Single-hostname check for subdomains (www.example.com, app.example.com, etc.).
 */
function _cfz_verify_dns_single($hostname, $a_lookup, $cname_lookup, $resolver)
{
    $target_ip = CFZ_DNS_SERVER_IP;
    $target_cn = CFZ_DNS_RUNTIME_HOST;
    $observed  = [];

    foreach ((array) $a_lookup($hostname) as $r) {
        if (!empty($r['ip'])) { $observed[] = ['type' => 'A', 'value' => $r['ip']]; }
    }
    foreach ((array) $cname_lookup($hostname) as $r) {
        $target = strtolower(rtrim($r['target'] ?? '', '.'));
        if ($target !== '') { $observed[] = ['type' => 'CNAME', 'value' => $target]; }
    }

    // Direct A record match
    foreach ($observed as $rec) {
        if ($rec['type'] === 'A' && $rec['value'] === $target_ip) {
            return ['status' => 'verified', 'reason' => 'A record resolves to the ClickFuzz server.', 'records' => $observed];
        }
    }

    // CNAME → sites.clickfuzz.com + IP chain
    foreach ($observed as $rec) {
        if ($rec['type'] === 'CNAME' && $rec['value'] === $target_cn) {
            if ($resolver($hostname) === $target_ip) {
                return ['status' => 'verified', 'reason' => 'CNAME is correctly set and resolves to the ClickFuzz server.', 'records' => $observed];
            }
            return ['status' => 'pending', 'reason' => 'CNAME is correctly pointed to ClickFuzz but the IP has not fully propagated yet.', 'records' => $observed];
        }
    }

    // Wrong records
    if (!empty($observed)) {
        $types = implode(', ', array_map(fn($r) => $r['type'] . ' ' . $r['value'], $observed));
        return ['status' => 'failed', 'reason' => 'DNS records found but not pointing to ClickFuzz (' . $types . ').', 'records' => $observed];
    }

    return ['status' => 'pending', 'reason' => 'No DNS records found yet. This may still be propagating.', 'records' => []];
}
