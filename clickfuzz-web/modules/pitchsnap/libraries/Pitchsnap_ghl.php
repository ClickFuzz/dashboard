<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pitchsnap_ghl
{
    const BASE_URL = 'https://services.leadconnectorhq.com';
    const TIMEOUT  = 15;

    private $api_key;

    public function __construct()
    {
        $this->api_key = (string) get_option('pitchsnap_ghl_api_key');
    }

    public function is_configured()
    {
        return $this->api_key !== '';
    }

    /**
     * Fetch a GHL location by ID.
     * Returns ['success' => true, 'data' => [...]] or ['success' => false, 'error' => '...']
     */
    public function get_location($location_id)
    {
        return $this->request('GET', '/locations/' . rawurlencode($location_id));
    }

    /**
     * Search available US local phone numbers for a given location.
     *
     * @param  string $location_id  Agency GHL location ID
     * @param  string $search       Digits to search (area code or partial number, min 3 chars)
     * @param  int    $limit        Max results to return
     * @return array  ['success' => bool, 'numbers' => array, 'error' => string|null]
     */
    public function search_available_numbers($location_id, $search, $limit = 10)
    {
        $search = preg_replace('/[^0-9]/', '', (string) $search);
        if (strlen($search) < 3) {
            return ['success' => false, 'numbers' => [], 'error' => 'Please enter at least 3 digits.'];
        }

        $params = http_build_query([
            'firstPart'   => $search,
            'numberTypes' => 'local',
            'countryCode' => 'US',
            'smsEnabled'  => 'true',
            'voiceEnabled'=> 'true',
        ]);

        $path = '/phone-system/numbers/location/' . rawurlencode($location_id) . '/available?' . $params;
        $resp = $this->request('GET', $path);

        if (!$resp['success']) {
            return ['success' => false, 'numbers' => [], 'error' => $resp['error']];
        }

        // Normalise response — API returns array at root or under a key
        $raw = $resp['data'] ?? [];
        if (isset($raw['available']) && is_array($raw['available'])) {
            $raw = $raw['available'];
        } elseif (isset($raw['numbers']) && is_array($raw['numbers'])) {
            $raw = $raw['numbers'];
        } elseif (!isset($raw[0])) {
            $raw = [];
        }

        $numbers = [];
        foreach (array_slice($raw, 0, $limit) as $item) {
            $e164 = isset($item['phoneNumber']) ? (string) $item['phoneNumber']
                  : (isset($item['number'])      ? (string) $item['number'] : null);
            if (!$e164 || !preg_match('/^\+?1?[2-9]\d{9}$/', preg_replace('/[^0-9+]/', '', $e164))) {
                continue;
            }
            // Normalise to E.164
            $digits = preg_replace('/[^0-9]/', '', $e164);
            if (strlen($digits) === 10) { $digits = '1' . $digits; }
            $numbers[] = [
                'e164'     => '+' . $digits,
                'friendly' => '(' . substr($digits, 1, 3) . ') ' . substr($digits, 4, 3) . '-' . substr($digits, 7),
            ];
        }

        return ['success' => true, 'numbers' => $numbers, 'error' => null];
    }

    private function request($method, $path, $body = null)
    {
        if (!$this->is_configured()) {
            return ['success' => false, 'error' => 'GHL API key not configured.'];
        }

        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->api_key,
                'Version: 2021-07-28',
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'cURL error: ' . $err];
        }

        $decoded = json_decode($raw, true);

        if ($status >= 200 && $status < 300) {
            return ['success' => true, 'status' => $status, 'data' => $decoded ?? []];
        }

        $msg = (is_array($decoded) && !empty($decoded['message'])) ? $decoded['message'] : ('HTTP ' . $status);
        return ['success' => false, 'error' => $msg, 'status' => $status];
    }
}
