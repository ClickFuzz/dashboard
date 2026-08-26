<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Normalize a user-entered domain string to a bare hostname.
 *
 * Strips scheme, path, query, fragment, port, and trailing dot.
 * Does NOT strip www — that is a meaningful subdomain choice.
 *
 * Returns the normalized string, or empty string if input is unusable.
 */
function clickfuzz_web_normalize_hostname($input)
{
    $input = trim((string) $input);
    if ($input === '') {
        return '';
    }

    // Strip scheme so parse_url can handle scheme-less input too
    $with_scheme = preg_match('#^https?://#i', $input) ? $input : 'http://' . $input;
    $parts = parse_url($with_scheme);
    if (!$parts || empty($parts['host'])) {
        return '';
    }

    $host = strtolower($parts['host']);
    $host = rtrim($host, '.');

    // Strip port if present (parse_url leaves host clean, but be safe)
    if (strpos($host, ':') !== false) {
        $host = explode(':', $host)[0];
    }

    return $host;
}

/**
 * Validate a normalized hostname for use as a custom domain.
 *
 * $hostname  — already normalized via clickfuzz_web_normalize_hostname()
 * $site_id   — the site that would own this domain (for conflict checks)
 *
 * Returns null on success, or an error string on failure.
 */
function clickfuzz_web_validate_custom_hostname($hostname, $site_id)
{
    if ($hostname === '') {
        return 'Domain cannot be empty.';
    }

    // Must be a valid RFC hostname (no wildcards, no underscores at label start)
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $hostname)) {
        return 'That does not appear to be a valid domain name.';
    }

    // Reject IP addresses (v4 — all-numeric labels)
    if (preg_match('/^(?:\d{1,3}\.){3}\d{1,3}$/', $hostname)) {
        return 'IP addresses cannot be used as custom domains.';
    }

    // Reject localhost
    if ($hostname === 'localhost') {
        return 'That hostname is not allowed.';
    }

    // Reject wildcard input (would have been caught by the RFC regex above, but be explicit)
    if (strpos($hostname, '*') !== false) {
        return 'Wildcard hostnames are not allowed.';
    }

    // Reject clickfuzz.com itself
    if ($hostname === 'clickfuzz.com') {
        return 'That hostname is reserved.';
    }

    // Reject *.clickfuzz.com platform hostnames
    if (substr($hostname, -strlen('.clickfuzz.com')) === '.clickfuzz.com') {
        return 'That hostname is reserved for ClickFuzz platform domains.';
    }

    // Conflict check — hostname already used by any site (including this one in other mapping)
    $CI =& get_instance();
    if (!property_exists($CI, 'pitchsnap_model') || !$CI->pitchsnap_model) {
        $CI->load->model('pitchsnap_model');
    }
    if (!$CI->pitchsnap_model->hostname_available_for_site($hostname, (int) $site_id)) {
        return 'That domain is already assigned to another ClickFuzz site.';
    }

    return null;
}
