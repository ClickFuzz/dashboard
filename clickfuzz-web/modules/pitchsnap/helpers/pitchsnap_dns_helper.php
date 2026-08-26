<?php
defined('BASEPATH') or exit('No direct script access allowed');

if (!defined('CFZ_DNS_SERVER_IP'))    { define('CFZ_DNS_SERVER_IP',    '104.152.168.38'); }
if (!defined('CFZ_DNS_RUNTIME_HOST')) { define('CFZ_DNS_RUNTIME_HOST', 'sites.clickfuzz.com'); }

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
 * Each record: ['type', 'host', 'value', 'note']
 * 'host' is the relative label used in most DNS UIs (@ for apex).
 */
function clickfuzz_web_expected_dns_records($hostname)
{
    if (clickfuzz_web_hostname_is_apex($hostname)) {
        // Apex: A record for root domain; CNAME for www is recommended alongside it.
        return [
            ['type' => 'A',     'host' => '@',   'value' => CFZ_DNS_SERVER_IP,    'note' => 'Points your root domain to ClickFuzz'],
            ['type' => 'CNAME', 'host' => 'www', 'value' => CFZ_DNS_RUNTIME_HOST, 'note' => 'Also connects www (recommended)'],
        ];
    }

    // Subdomain (www.example.com, app.example.com, etc.)
    $parts = explode('.', $hostname);
    $label = $parts[0]; // 'www', 'app', etc.
    return [
        ['type' => 'CNAME', 'host' => $label, 'value' => CFZ_DNS_RUNTIME_HOST, 'note' => 'Points your subdomain to ClickFuzz'],
    ];
}

/**
 * Verify that a custom hostname currently resolves to ClickFuzz infrastructure.
 *
 * $hostname      — normalized hostname to check
 * $a_lookup      — callable($host) → array of {ip} records  (injectable for tests)
 * $cname_lookup  — callable($host) → array of {target} records (injectable for tests)
 * $resolver      — callable($host) → string IP or original hostname (injectable for tests)
 *
 * Returns:
 *   status  — 'verified' | 'pending' | 'failed'
 *   reason  — human-readable explanation (do not expose server internals)
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

    $target_ip = CFZ_DNS_SERVER_IP;
    $target_cn = CFZ_DNS_RUNTIME_HOST;
    $observed  = [];

    // Gather A records
    $a_recs = $a_lookup($hostname);
    foreach ((array) $a_recs as $r) {
        if (!empty($r['ip'])) { $observed[] = ['type' => 'A', 'value' => $r['ip']]; }
    }

    // Gather CNAME records (not valid at apex, but check anyway in case of misconfiguration)
    $cn_recs = $cname_lookup($hostname);
    foreach ((array) $cn_recs as $r) {
        $target = rtrim($r['target'] ?? '', '.');
        if ($target !== '') { $observed[] = ['type' => 'CNAME', 'value' => strtolower($target)]; }
    }

    // 1. Direct A record match
    foreach ($observed as $rec) {
        if ($rec['type'] === 'A' && $rec['value'] === $target_ip) {
            return [
                'status'  => 'verified',
                'reason'  => 'A record resolves to the ClickFuzz server.',
                'records' => $observed,
            ];
        }
    }

    // 2. CNAME → sites.clickfuzz.com
    foreach ($observed as $rec) {
        if ($rec['type'] === 'CNAME' && $rec['value'] === $target_cn) {
            // CNAME target is correct; verify the chain resolves to our IP
            $resolved = $resolver($hostname);
            if ($resolved === $target_ip) {
                return [
                    'status'  => 'verified',
                    'reason'  => 'CNAME is correctly set and resolves to the ClickFuzz server.',
                    'records' => $observed,
                ];
            }
            // CNAME is correct but IP resolution not yet there
            return [
                'status'  => 'pending',
                'reason'  => 'CNAME is correctly pointed to ClickFuzz but the IP has not fully propagated yet.',
                'records' => $observed,
            ];
        }
    }

    // 3. Has records but pointing elsewhere — explicit misconfiguration
    if (!empty($observed)) {
        $types = implode(', ', array_map(fn($r) => $r['type'] . ' ' . $r['value'], $observed));
        return [
            'status'  => 'failed',
            'reason'  => 'DNS records found but not pointing to ClickFuzz (' . $types . ').',
            'records' => $observed,
        ];
    }

    // 4. No records at all — not configured yet, or still propagating
    return [
        'status'  => 'pending',
        'reason'  => 'No DNS records found yet. This may still be propagating.',
        'records' => [],
    ];
}
