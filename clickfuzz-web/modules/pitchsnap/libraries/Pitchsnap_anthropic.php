<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pitchsnap_anthropic
{
    const API_URL     = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';

    private $api_key;
    private $model;

    public function __construct()
    {
        $this->api_key = get_option('pitchsnap_anthropic_api_key');
        $this->model   = get_option('pitchsnap_model') ?: 'claude-sonnet-4-6';
    }

    /**
     * Submit prompt to Anthropic Messages API.
     *
     * @param  string $prompt
     * @return array  ['success' => bool, 'result' => string|null, 'error' => string|null, 'model' => string|null]
     */
    public function generate($prompt)
    {
        if (!$this->api_key) {
            return $this->fail('Anthropic API key not configured.');
        }

        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => 32000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: '         . $this->api_key,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return $this->fail('cURL error: ' . $curl_error);
        }

        $data = json_decode($response, true);

        if ($http_code !== 200) {
            $msg = isset($data['error']['message'])
                ? $data['error']['message']
                : 'HTTP ' . $http_code . ': ' . substr((string) $response, 0, 300);
            return $this->fail($msg);
        }

        $text = isset($data['content'][0]['text']) ? $data['content'][0]['text'] : null;
        if (!$text) {
            return $this->fail('Empty or unexpected response from Anthropic.');
        }

        return [
            'success' => true,
            'result'  => $text,
            'error'   => null,
            'model'   => isset($data['model']) ? $data['model'] : $this->model,
        ];
    }

    /**
     * Extract structured fields from a document or image using vision.
     *
     * @param  string $file_path        Absolute path to the file on disk
     * @param  string $mime_type        'application/pdf', 'image/jpeg', or 'image/png'
     * @param  array  $extraction_fields Array of field keys to extract: business_name, ein,
     *                                   street_address, city, state, postal_code
     * @return array  ['success'=>bool, 'extracted'=>array|null, 'error'=>string|null]
     */
    public function extract_document($file_path, $mime_type, array $extraction_fields)
    {
        if (!$this->api_key) {
            return ['success' => false, 'extracted' => null, 'error' => 'Anthropic API key not configured.'];
        }
        if (!is_file($file_path)) {
            return ['success' => false, 'extracted' => null, 'error' => 'File not found for extraction.'];
        }

        $raw = @file_get_contents($file_path);
        if ($raw === false) {
            return ['success' => false, 'extracted' => null, 'error' => 'Could not read file for extraction.'];
        }
        $b64 = base64_encode($raw);

        $field_descriptions = [
            'business_name'  => 'Legal business name (as printed on the document)',
            'ein'            => 'EIN / Employer Identification Number (format: XX-XXXXXXX)',
            'street_address' => 'Street address (number and street name only, no city/state/zip)',
            'city'           => 'City',
            'state'          => 'State (2-letter abbreviation)',
            'postal_code'    => 'ZIP / Postal code',
        ];

        $field_lines = [];
        foreach ($extraction_fields as $f) {
            if (isset($field_descriptions[$f])) {
                $field_lines[] = '"' . $f . '": "' . $field_descriptions[$f] . ', or null if not found"';
            }
        }

        $prompt = 'You are a document data extractor. Extract only the requested fields from the provided document. '
            . 'Return ONLY a JSON object with no explanation, markdown, or commentary. '
            . 'If a field is not present in the document, use null. '
            . 'Requested fields: {' . implode(', ', $field_lines) . '}';

        if ($mime_type === 'application/pdf') {
            $content_block = [
                'type'   => 'document',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64],
            ];
            $extra_headers = ['anthropic-beta: pdfs-2024-09-25'];
        } else {
            $content_block = [
                'type'   => 'image',
                'source' => ['type' => 'base64', 'media_type' => $mime_type, 'data' => $b64],
            ];
            $extra_headers = [];
        }

        $body = json_encode([
            'model'      => $this->model,
            'max_tokens' => 512,
            'messages'   => [[
                'role'    => 'user',
                'content' => [$content_block, ['type' => 'text', 'text' => $prompt]],
            ]],
        ]);

        $headers = [
            'x-api-key: '         . $this->api_key,
            'anthropic-version: ' . self::API_VERSION,
            'content-type: application/json',
        ];
        foreach ($extra_headers as $h) {
            $headers[] = $h;
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['success' => false, 'extracted' => null, 'error' => 'cURL error: ' . $curl_error];
        }

        $data = json_decode($response, true);

        if ($http_code !== 200) {
            $msg = isset($data['error']['message'])
                ? $data['error']['message']
                : 'HTTP ' . $http_code;
            return ['success' => false, 'extracted' => null, 'error' => $msg];
        }

        $text = isset($data['content'][0]['text']) ? trim($data['content'][0]['text']) : null;
        if (!$text) {
            return ['success' => false, 'extracted' => null, 'error' => 'Empty extraction response.'];
        }

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $extracted = json_decode(trim($text), true);
        if (!is_array($extracted)) {
            return ['success' => false, 'extracted' => null, 'error' => 'Could not parse extraction response as JSON.'];
        }

        // Sanitize: only return requested fields with string/null values
        $clean = [];
        foreach ($extraction_fields as $f) {
            $val = $extracted[$f] ?? null;
            $clean[$f] = (is_string($val) && $val !== '') ? trim($val) : null;
        }

        return ['success' => true, 'extracted' => $clean, 'error' => null];
    }

    private function fail($message)
    {
        return ['success' => false, 'result' => null, 'error' => $message, 'model' => $this->model];
    }
}
