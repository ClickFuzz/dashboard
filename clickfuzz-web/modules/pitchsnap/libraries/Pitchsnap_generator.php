<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Thin provider abstraction — cron and controllers talk to this,
 * never directly to provider libraries.
 */
class Pitchsnap_generator
{
    private $provider_name;

    /**
     * @param string|null $provider_name  Force a specific provider; null reads from settings.
     */
    public function __construct($provider_name = null)
    {
        if ($provider_name !== null) {
            $this->provider_name = $provider_name;
        } else {
            $this->provider_name = get_option('pitchsnap_primary_provider') ?: 'manus';
        }
    }

    public function get_provider_name()
    {
        return $this->provider_name;
    }

    /**
     * True when the provider runs asynchronously (Manus).
     * False for synchronous providers (Anthropic).
     */
    public function is_async()
    {
        return $this->provider_name === 'manus';
    }

    // -----------------------------------------------------------------------
    // Synchronous path — Anthropic
    // -----------------------------------------------------------------------

    /**
     * @param  string $prompt
     * @return array  ['success' => bool, 'result' => string|null, 'error' => string|null, 'model' => string|null]
     */
    public function generate($prompt)
    {
        return $this->_anthropic()->generate($prompt);
    }

    // -----------------------------------------------------------------------
    // Asynchronous path — Manus
    // -----------------------------------------------------------------------

    /**
     * Start a Manus generation task. Returns immediately.
     *
     * @param  string $prompt
     * @return array  ['success' => bool, 'task_id' => string|null, 'error' => string|null]
     */
    public function start($prompt)
    {
        return $this->_manus()->start($prompt);
    }

    /**
     * Poll an in-progress Manus task.
     *
     * @param  string $task_id
     * @return array  ['status' => 'running'|'stopped'|'waiting'|'error', 'error' => string|null]
     */
    public function poll_task($task_id)
    {
        return $this->_manus()->poll_task($task_id);
    }

    /**
     * Publish the website produced by a completed Manus task.
     *
     * @param  string $task_id
     * @return array  ['success' => bool, 'website_id' => string|null, 'error' => string|null]
     */
    public function publish($task_id)
    {
        return $this->_manus()->publish($task_id);
    }

    /**
     * Poll Manus website deployment status.
     *
     * @param  string $website_id
     * @return array  ['status' => 'publishing'|'published'|'failed', 'url' => string|null, 'error' => string|null]
     */
    public function poll_publish($website_id)
    {
        return $this->_manus()->poll_publish($website_id);
    }

    /**
     * Return true if the error text suggests a Manus quota/credit exhaustion,
     * which is the signal to auto-fallback to Anthropic.
     */
    public function is_quota_error($error)
    {
        return $this->_manus()->is_quota_error($error);
    }

    // -----------------------------------------------------------------------
    // Private provider loaders
    // -----------------------------------------------------------------------

    private function _manus()
    {
        if (!class_exists('Pitchsnap_manus')) {
            require_once FCPATH . 'modules/pitchsnap/libraries/Pitchsnap_manus.php';
        }
        return new Pitchsnap_manus();
    }

    private function _anthropic()
    {
        if (!class_exists('Pitchsnap_anthropic')) {
            require_once FCPATH . 'modules/pitchsnap/libraries/Pitchsnap_anthropic.php';
        }
        return new Pitchsnap_anthropic();
    }
}
