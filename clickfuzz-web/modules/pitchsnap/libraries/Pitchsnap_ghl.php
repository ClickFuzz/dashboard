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
     * Fetch all custom fields for a GHL location.
     * Returns ['success' => true, 'data' => ['customFields' => [...]]] or error.
     */
    public function get_custom_fields($location_id)
    {
        return $this->request('GET', '/locations/' . rawurlencode($location_id) . '/customFields');
    }

    /**
     * Create a custom field in a GHL location.
     * $data_type: GHL dataType string (TEXT, NUMERICAL, DATE, CHECKBOX, DROPDOWN, etc.)
     * Returns ['success' => true, 'data' => ['customField' => [...]]] or error.
     */
    public function create_custom_field($location_id, $name, $data_type = 'TEXT')
    {
        return $this->request('POST', '/locations/' . rawurlencode($location_id) . '/customFields', [
            'name'     => $name,
            'dataType' => $data_type,
        ]);
    }

    /**
     * Create or upsert a contact in a GHL location.
     * $fields: associative array with standard GHL contact keys
     *   (firstName, lastName, phone, email, name, customField, etc.)
     * Returns ['success' => true, 'contact_id' => '...'] or ['success' => false, 'error' => '...']
     */
    public function create_contact($location_id, array $fields)
    {
        $body   = array_merge($fields, ['locationId' => $location_id]);
        $result = $this->request('POST', '/contacts/', $body);
        if (!$result['success']) {
            return $result;
        }
        $contact_id = $result['data']['contact']['id'] ?? ($result['data']['id'] ?? null);
        return ['success' => true, 'contact_id' => $contact_id];
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
