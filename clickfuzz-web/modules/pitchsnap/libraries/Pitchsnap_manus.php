<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pitchsnap_manus
{
    const BASE_URL = 'https://api.manus.ai/v2/';

    private $api_key;

    public function __construct()
    {
        $this->api_key = get_option('pitchsnap_manus_api_key');
    }

    /**
     * Start a Manus website generation task.
     * Returns immediately — task runs asynchronously.
     *
     * @param  string $prompt  Fully rendered prompt (placeholders already filled)
     * @return array  ['success' => bool, 'task_id' => string|null, 'error' => string|null]
     */
    public function start($prompt)
    {
        if (!$this->api_key) {
            return $this->fail_start('Manus API key not configured.');
        }

        $body = json_encode([
            'message'          => ['content' => $prompt],
            'share_visibility' => 'public',
            'interactive_mode' => false,
        ]);

        $resp = $this->_post('task.create', $body);

        if (!$resp['ok']) {
            return $this->fail_start($resp['error']);
        }

        $task_id = $resp['data']['task_id'] ?? null;
        if (!$task_id) {
            return $this->fail_start('Manus returned no task_id.');
        }

        return ['success' => true, 'task_id' => $task_id, 'error' => null];
    }

    /**
     * Poll the status of a running Manus task.
     *
     * HTTP 404 means the task is not yet registered in Manus (normal immediately
     * after task.create). HTTP 5xx is a transient server error. Both are treated
     * as 'running' so the cron leaves the record in generating state and retries
     * on the next cycle. Only a confirmed Manus application-level error
     * (ok=false with a non-transient code) returns status='error'.
     *
     * @param  string $task_id
     * @return array  ['status' => 'running'|'stopped'|'waiting'|'error', 'error' => string|null]
     */
    public function poll_task($task_id)
    {
        $resp = $this->_get('task.detail', ['task_id' => $task_id]);

        if (!$resp['ok']) {
            $code = $resp['http_code'];
            // 404: task not yet indexed; 5xx: transient server error; 0: cURL/network issue
            if ($code === 404 || ($code >= 500 && $code < 600) || $code === 0) {
                return ['status' => 'running', 'error' => null];
            }
            return ['status' => 'error', 'error' => $resp['error']];
        }

        $status = $resp['data']['task']['status'] ?? 'error';
        return ['status' => $status, 'error' => null];
    }

    /**
     * Publish the website produced by a completed Manus task.
     * Call this after poll_task returns 'stopped'.
     *
     * @param  string $task_id
     * @return array  ['success' => bool, 'website_id' => string|null, 'error' => string|null]
     */
    public function publish($task_id)
    {
        if (!$this->api_key) {
            return $this->fail_publish('Manus API key not configured.');
        }

        $body = json_encode([
            'task_id'    => $task_id,
            'visibility' => 'public',
        ]);

        $resp = $this->_post('website.publish', $body);

        if (!$resp['ok']) {
            return $this->fail_publish($resp['error']);
        }

        $website_id = $resp['data']['website_id'] ?? null;
        if (!$website_id) {
            return $this->fail_publish('Manus returned no website_id.');
        }

        return ['success' => true, 'website_id' => $website_id, 'error' => null];
    }

    /**
     * Poll website deployment status and retrieve the live URL when published.
     *
     * @param  string $website_id
     * @return array  ['status' => 'publishing'|'published'|'failed'|'unpublished', 'url' => string|null, 'error' => string|null]
     */
    public function poll_publish($website_id)
    {
        $resp = $this->_get('website.status', ['website_id' => $website_id]);

        if (!$resp['ok']) {
            return ['status' => 'error', 'url' => null, 'error' => $resp['error']];
        }

        $status    = $resp['data']['publish_status'] ?? 'failed';
        $site_urls = $resp['data']['site_urls']      ?? [];
        $url       = null;

        if ($status === 'published' && !empty($site_urls)) {
            $candidate = $site_urls[0];
            if (filter_var($candidate, FILTER_VALIDATE_URL) && strncmp($candidate, 'https://', 8) === 0) {
                $url = $candidate;
            } else {
                $status = 'failed';
            }
        }

        return ['status' => $status, 'url' => $url, 'error' => null];
    }

    /**
     * Return true if the error message indicates a quota/credit exhaustion.
     * Used to decide whether automatic fallback to Anthropic is appropriate.
     */
    public function is_quota_error($error_message)
    {
        $lower = strtolower((string) $error_message);
        foreach (['credit', 'quota', 'limit', 'insufficient', 'balance'] as $kw) {
            if (strpos($lower, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Private HTTP helpers
    // -----------------------------------------------------------------------

    private function _post($endpoint, $body)
    {
        $ch = curl_init(self::BASE_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'x-manus-api-key: ' . $this->api_key,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        return $this->_exec($ch);
    }

    private function _get($endpoint, array $params = [])
    {
        $url = self::BASE_URL . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'x-manus-api-key: ' . $this->api_key,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        return $this->_exec($ch);
    }

    private function _exec($ch)
    {
        $raw        = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return ['ok' => false, 'http_code' => 0, 'error' => 'cURL: ' . $curl_error, 'data' => []];
        }

        $data = json_decode((string) $raw, true);

        if ($http_code < 200 || $http_code >= 300) {
            $msg = isset($data['error']['message'])
                ? $data['error']['message']
                : 'HTTP ' . $http_code . ': ' . substr((string) $raw, 0, 300);
            return ['ok' => false, 'http_code' => $http_code, 'error' => $msg, 'data' => []];
        }

        if (empty($data['ok'])) {
            $msg = $data['error']['message'] ?? 'Unknown Manus error.';
            return ['ok' => false, 'http_code' => $http_code, 'error' => $msg, 'data' => []];
        }

        return ['ok' => true, 'http_code' => $http_code, 'error' => null, 'data' => $data];
    }

    private function fail_start($msg)
    {
        return ['success' => false, 'task_id' => null, 'error' => $msg];
    }

    private function fail_publish($msg)
    {
        return ['success' => false, 'website_id' => null, 'error' => $msg];
    }
}
