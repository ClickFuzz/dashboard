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

    private function fail($message)
    {
        return ['success' => false, 'result' => null, 'error' => $message, 'model' => $this->model];
    }
}
