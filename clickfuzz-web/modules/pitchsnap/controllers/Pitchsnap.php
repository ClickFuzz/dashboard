<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pitchsnap extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('pitchsnap_model');
    }

    public function websites()
    {
        if (!is_staff_member()) { access_denied('ClickFuzz Web'); }
        $data['title']    = 'ClickFuzz Web Websites';
        $data['websites'] = $this->pitchsnap_model->get_latest_per_lead();
        $this->load->view('pitchsnap/admin_websites', $data);
    }

    public function redesigns()
    {
        redirect(admin_url('pitchsnap/websites'));
    }

    public function lead($lead_id = '')
    {
        if (!is_staff_member()) { access_denied('ClickFuzz Web'); }
        $lead_id = (int) $lead_id;
        if (!$lead_id) { redirect(admin_url('pitchsnap/websites')); }
        $this->load->model('leads_model');
        $lead = $this->leads_model->get($lead_id);
        if (!$lead) { show_404(); }
        $history = $this->pitchsnap_model->get_by_lead($lead_id);
        $latest  = !empty($history) ? (object) $history[0] : null;
        $data['title']         = 'ClickFuzz Web — ' . $lead->name;
        $data['lead']          = $lead;
        $data['history']       = $history;
        $data['latest']        = $latest;
        $data['conversations'] = $latest ? $this->pitchsnap_model->get_conversations((int) $latest->id) : [];
        $this->load->view('pitchsnap/admin_lead', $data);
    }

    public function detail($id = '')
    {
        if (!is_staff_member()) { access_denied('ClickFuzz Web'); }
        if (!is_numeric($id) || (int) $id < 1) { redirect(admin_url('pitchsnap/websites')); }
        $entry = $this->pitchsnap_model->get($id);
        if (!$entry) { show_404(); }
        $lead_id  = (int) $entry->lead_id;
        $primary  = $this->pitchsnap_model->get_primary_for_lead($lead_id);
        $versions = $this->pitchsnap_model->get_versions_for_lead($lead_id);
        $website  = $primary ?: $entry;
        $this->load->model('leads_model');
        $lead = $this->leads_model->get($lead_id);
        $data['title']         = 'Websites — ' . ($lead ? e($lead->name) : 'Lead #' . $lead_id);
        $data['website']       = $website;
        $data['versions']      = $versions;
        $data['lead']          = $lead;
        $data['conversations'] = $this->pitchsnap_model->get_conversations($website->id);
        $data['site']          = $this->pitchsnap_model->get_site_by_website_id((int) $website->id);
        $data['agreement']     = !empty($data['site']) ? $this->pitchsnap_model->get_agreement_by_site($data['site']->id) : null;
        $this->load->view('admin_detail', $data);
    }

    public function approve_design($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if (!$this->input->post()) { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $website    = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        $detail_url = admin_url('pitchsnap/detail/' . $id);

        if (in_array($website->status, ['approved', 'sent', 'viewed'])) {
            set_alert('info', 'This design was already approved.');
            redirect($detail_url);
        }
        if (!in_array($website->status, ['review_required'])) {
            set_alert('warning', 'Cannot approve — status is: ' . $website->status);
            redirect($detail_url);
        }

        $staff_id = (int) get_staff_user_id();
        $this->pitchsnap_model->mark_approved($id, $staff_id);

        if (empty($website->prospect_notified_at)) {
            $site = $this->pitchsnap_model->get_site_by_website_id($id);
            $client_email = null;
            if ($site && !empty($site->client_id)) {
                if (!function_exists('clickfuzz_web_get_client_email')) {
                    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_cron_helper.php';
                }
                $client_email = clickfuzz_web_get_client_email((int) $site->client_id);
            }
            if ($client_email) {
                $client_row = $this->db->select('company')->where('userid', (int) $site->client_id)->get(db_prefix() . 'clients')->row();
                $company = $client_row ? $client_row->company : '';
                if (!function_exists('clickfuzz_web_send_mail')) {
                    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_cron_helper.php';
                }
                clickfuzz_web_send_mail('pitchsnap-website-ready', $client_email, [
                    '{company}'            => $company,
                    '{website_review_url}' => base_url('pitchsnap/track_view/' . $website->preview_token),
                ]);
                $this->pitchsnap_model->update($id, ['prospect_notified_at' => date('Y-m-d H:i:s'), 'status' => 'sent']);
            } else {
                $this->pitchsnap_model->update($id, ['status' => 'sent']);
            }
        }

        hooks()->do_action('clickfuzz_web_website_approved', ['website_id' => $id, 'staff_id' => $staff_id]);
        log_activity('ClickFuzz Web: Design approved [Website ID: ' . $id . ', Staff: ' . $staff_id . ']');
        set_alert('success', 'Design approved. Prospect has been notified.');
        redirect($detail_url);
    }

    public function set_primary($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if (!$this->input->post()) { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid ID.'); redirect(admin_url('pitchsnap/websites')); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        $this->pitchsnap_model->set_primary_version($id, $website->lead_id);
        log_activity('ClickFuzz Web: Version set as primary [Website ID: ' . $id . ']');
        set_alert('success', 'Version #' . $id . ' is now the primary version.');
        redirect(admin_url('pitchsnap/detail/' . $id));
    }

    public function delete_versions()
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if (!$this->input->post()) { redirect(admin_url('pitchsnap/websites')); }
        $ids         = $this->input->post('ids');
        $redirect_id = (int) $this->input->post('redirect_id');
        if (!is_array($ids) || empty($ids)) {
            set_alert('warning', 'No versions selected.');
            redirect(admin_url('pitchsnap/detail/' . $redirect_id));
        }
        $deleted = 0;
        $skipped = 0;
        foreach ($ids as $vid) {
            $vid = (int) $vid;
            if (!$vid) continue;
            if ($this->pitchsnap_model->delete_redesign($vid)) { $deleted++; }
            else { $skipped++; }
        }
        $msg = $deleted . ' version(s) deleted.';
        if ($skipped) { $msg .= ' ' . $skipped . ' skipped (primary or invalid).'; }
        set_alert($deleted ? 'success' : 'warning', $msg);
        redirect(admin_url('pitchsnap/detail/' . $redirect_id));
    }

    public function delete_profile($lead_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->post('confirm_delete') !== '1') { redirect(admin_url('pitchsnap/websites')); }
        $lead_id = (int) $lead_id;
        if (!$lead_id) {
            set_alert('danger', 'Invalid lead ID.');
            redirect(admin_url('pitchsnap/websites'));
        }
        $deleted = $this->pitchsnap_model->delete_lead_profile($lead_id);
        if ($deleted) {
            set_alert('success', 'Website profile deleted. The lead remains in Perfex.');
        } else {
            set_alert('warning', 'No website profile found for this lead, or it was already removed.');
        }
        redirect(admin_url('pitchsnap/websites'));
    }

    public function publish_site($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if (!$this->input->post()) { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) {
            set_alert('danger', 'Invalid website ID.');
            redirect(admin_url('pitchsnap/websites'));
        }
        $detail_url = admin_url('pitchsnap/detail/' . $id);
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) {
            set_alert('danger', 'No site record found. Trigger a generation first so ClickFuzz Web can create the site record.');
            redirect($detail_url);
        }
        if (!function_exists('clickfuzz_web_publish_site')) {
            require_once FCPATH . 'modules/pitchsnap/pitchsnap.php';
        }
        $result = clickfuzz_web_publish_site($site->id);
        if ($result['success']) {
            log_activity('ClickFuzz Web: Site published [Site ID: ' . $site->id . ', URL: ' . $result['url'] . ']');
            set_alert('success', 'Site published at <a href="' . $result['url'] . '" target="_blank">' . $result['url'] . '</a>');
        } else {
            set_alert('danger', 'Publish failed: ' . $result['error']);
        }
        redirect($detail_url);
    }

    public function save_custom_domain($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if (!$this->input->post()) { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) {
            set_alert('danger', 'Invalid website ID.');
            redirect(admin_url('pitchsnap/websites'));
        }
        $detail_url = admin_url('pitchsnap/detail/' . $id);
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) {
            set_alert('danger', 'No site record found for this website.');
            redirect($detail_url);
        }

        if (!function_exists('clickfuzz_web_normalize_hostname')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_domain_helper.php';
        }

        $raw      = $this->input->post('custom_domain', true);
        $hostname = clickfuzz_web_normalize_hostname($raw);
        $error    = clickfuzz_web_validate_custom_hostname($hostname, $site->id);
        if ($error) {
            set_alert('danger', $error);
            redirect($detail_url);
        }

        $result = $this->pitchsnap_model->save_custom_domain($site->id, $hostname);
        if ($result) {
            log_activity('ClickFuzz Web: Custom domain saved [Site ID: ' . $site->id . ', Hostname: ' . $hostname . ']');
            set_alert('success', 'Custom domain <strong>' . e($hostname) . '</strong> saved. DNS setup is pending.');
        } else {
            set_alert('danger', 'Failed to save custom domain. Please try again.');
        }
        redirect($detail_url);
    }

    public function remove_custom_domain($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if (!$this->input->post()) { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) {
            set_alert('danger', 'Invalid website ID.');
            redirect(admin_url('pitchsnap/websites'));
        }
        $detail_url = admin_url('pitchsnap/detail/' . $id);
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) {
            set_alert('danger', 'No site record found.');
            redirect($detail_url);
        }

        $removed = $this->pitchsnap_model->remove_custom_domain($site->id);
        if ($removed) {
            log_activity('ClickFuzz Web: Custom domain removed [Site ID: ' . $site->id . ']');
            set_alert('success', 'Custom domain removed.');
        } else {
            set_alert('warning', 'No custom domain was set.');
        }
        redirect($detail_url);
    }

    public function settings()
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->post()) { $this->_save_settings(); return; }
        $data['title']      = 'ClickFuzz Web Settings';
        $data['logs']       = $this->pitchsnap_model->get_logs(200);
        $data['active_tab'] = $this->input->get('tab') ?: 'general';
        $data['staff_list'] = $this->db->where('active', 1)->order_by('firstname', 'ASC')->get(db_prefix() . 'staff')->result();
        $this->load->view('pitchsnap/admin_settings', $data);
    }

    public function clear_logs()
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $this->pitchsnap_model->clear_logs();
        set_alert('success', 'Activity log cleared.');
        redirect(admin_url('pitchsnap/settings?tab=logs'));
    }

    public function get_logs_json()
    {
        if (!is_admin()) { return $this->_json(['success' => false]); }
        $logs = $this->pitchsnap_model->get_logs(200);
        return $this->_json(['success' => true, 'logs' => $logs]);
    }

    private function _save_settings()
    {
        $primary  = $this->input->post('pitchsnap_primary_provider', true);
        $fallback = $this->input->post('pitchsnap_fallback_provider', true);
        update_option('pitchsnap_primary_provider',  in_array($primary,  ['manus', 'anthropic']) ? $primary  : 'manus');
        update_option('pitchsnap_fallback_provider', in_array($fallback, ['none',  'anthropic']) ? $fallback : 'none');
        update_option('pitchsnap_ai_provider', $primary === 'anthropic' ? 'anthropic' : 'manus');

        $video_url = trim($this->input->post('pitchsnap_video_demo_url', true));
        update_option('pitchsnap_video_demo_url', $video_url);

        $agreement_version = trim($this->input->post('pitchsnap_agreement_version', true));
        $agreement_text    = $this->input->post('pitchsnap_agreement_text');
        if ($agreement_version !== '') { update_option('pitchsnap_agreement_version', $agreement_version); }
        update_option('pitchsnap_agreement_text', $agreement_text);

        $manus_key    = $this->input->post('pitchsnap_manus_api_key');
        $manus_prompt = $this->input->post('pitchsnap_manus_prompt');
        if (!empty($manus_key)) { update_option('pitchsnap_manus_api_key', trim($manus_key)); }
        update_option('pitchsnap_manus_prompt', $manus_prompt);

        $api_key = $this->input->post('pitchsnap_anthropic_api_key');
        $model   = trim($this->input->post('pitchsnap_model', true));
        $prompt  = $this->input->post('pitchsnap_generation_prompt');
        if (!empty($api_key)) { update_option('pitchsnap_anthropic_api_key', trim($api_key)); }
        update_option('pitchsnap_model',             $model ?: 'claude-sonnet-4-6');
        update_option('pitchsnap_generation_prompt', $prompt);

        $gr_names     = ['logo_usage','image_selection','team_placement','team_association','anonymous_team','gallery_usage','credential_usage','owner_story','visual_readability'];
        $gr_providers = ['anthropic','manus'];
        foreach ($gr_providers as $pkey) {
            foreach ($gr_names as $gkey) {
                $opt_key = 'pitchsnap_guardrail_' . $pkey . '_' . $gkey;
                update_option($opt_key, $this->input->post($opt_key) ? '1' : '0');
            }
        }

        update_option('pitchsnap_logging_enabled', $this->input->post('pitchsnap_logging_enabled') ? '1' : '0');

        // Pricing settings
        $payment_type = $this->input->post('pitchsnap_payment_type', true);
        update_option('pitchsnap_payment_type', in_array($payment_type, ['onetime', 'subscription']) ? $payment_type : 'onetime');

        $price = trim($this->input->post('pitchsnap_price', true));
        if ($price !== '' && is_numeric($price) && (float) $price > 0) {
            update_option('pitchsnap_price', number_format((float) $price, 2, '.', ''));
        }

        update_option('pitchsnap_stripe_plan_id',   trim($this->input->post('pitchsnap_stripe_plan_id', true)));
        $qty = (int) $this->input->post('pitchsnap_sub_quantity', true);
        update_option('pitchsnap_sub_quantity',     (string) max(1, $qty ?: 1));
        update_option('pitchsnap_sub_name',         trim($this->input->post('pitchsnap_sub_name', true)));
        update_option('pitchsnap_sub_description',  $this->input->post('pitchsnap_sub_description'));
        update_option('pitchsnap_sub_include_desc', $this->input->post('pitchsnap_sub_include_desc') ? '1' : '0');
        $sub_cur = (int) $this->input->post('pitchsnap_sub_currency', true);
        if ($sub_cur > 0) { update_option('pitchsnap_sub_currency', (string) $sub_cur); }
        update_option('pitchsnap_sub_tax1',         trim($this->input->post('pitchsnap_sub_tax1', true)));
        update_option('pitchsnap_sub_tax2',         trim($this->input->post('pitchsnap_sub_tax2', true)));

        $web_design_admin = (int) $this->input->post('pitchsnap_web_design_admin', true);
        update_option('pitchsnap_web_design_admin', (string) $web_design_admin);

        $tab = $this->input->post('active_tab');
        $tab = in_array($tab, ['general', 'ai', 'agreement', 'pricing', 'logs']) ? $tab : 'general';
        set_alert('success', 'ClickFuzz Web settings saved.');
        redirect(admin_url('pitchsnap/settings?tab=' . $tab));
    }

    public function queue_generate($id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        $id = (int) $id;
        if (!$id) { return $this->_json(['success' => false, 'message' => 'Invalid website ID.']); }
        $primary = get_option('pitchsnap_primary_provider') ?: 'manus';
        if ($primary === 'manus') {
            if (!get_option('pitchsnap_manus_api_key')) { return $this->_json(['success' => false, 'message' => 'Manus API key not configured. Go to ClickFuzz Web → Settings.']); }
            if (!trim((string) get_option('pitchsnap_manus_prompt'))) { return $this->_json(['success' => false, 'message' => 'Manus generation prompt not configured. Go to ClickFuzz Web → Settings.']); }
        } else {
            if (!get_option('pitchsnap_anthropic_api_key')) { return $this->_json(['success' => false, 'message' => 'Anthropic API key not configured. Go to ClickFuzz Web → Settings.']); }
            if (!trim((string) get_option('pitchsnap_generation_prompt'))) { return $this->_json(['success' => false, 'message' => 'Generation prompt not configured. Go to ClickFuzz Web → Settings.']); }
        }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { return $this->_json(['success' => false, 'message' => 'Website not found.']); }
        $queued = $this->pitchsnap_model->queue_for_generation($id);
        if (!$queued) { return $this->_json(['success' => false, 'message' => 'Cannot queue — current status does not allow generation (' . $website->status . ').']); }
        log_activity('ClickFuzz Web: Generation queued [Website ID: ' . $id . ']');
        return $this->_json(['success' => true, 'message' => 'Website #' . $id . ' queued for generation.']);
    }

    public function regenerate($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $id = (int) $id;
        if (!$id) { redirect(admin_url('pitchsnap/websites')); }
        $original = $this->pitchsnap_model->get($id);
        if (!$original) { show_404(); }
        $lead_url = admin_url('pitchsnap/lead/' . (int) $original->lead_id);
        $primary = get_option('pitchsnap_primary_provider') ?: 'manus';
        if ($primary === 'manus' && !get_option('pitchsnap_manus_api_key')) { set_alert('danger', 'Manus API key not configured. Go to ClickFuzz Web → Settings.'); redirect($lead_url); }
        if ($primary === 'anthropic' && !get_option('pitchsnap_anthropic_api_key')) { set_alert('danger', 'Anthropic API key not configured. Go to ClickFuzz Web → Settings.'); redirect($lead_url); }
        $new_id = $this->pitchsnap_model->create([
            'lead_id'             => $original->lead_id,
            'parent_redesign_id'  => $original->id,
            'original_url'        => $original->original_url,
            'vertical'            => $original->vertical,
            'status'              => 'pending_generation',
            'intake_role'         => $original->intake_role,
            'intake_company_size' => $original->intake_company_size,
            'intake_improvement'  => $original->intake_improvement,
            'addedfrom'           => (int) get_staff_user_id(),
        ]);
        if (!$new_id) { set_alert('danger', 'Could not create new version record. Please try again.'); redirect($lead_url); }
        log_activity('ClickFuzz Web: Regeneration queued [New ID: ' . $new_id . ', From: ' . $id . ']');
        set_alert('success', 'New version queued for generation.');
        redirect(admin_url('pitchsnap/detail/' . $new_id));
    }

    public function retry_anthropic($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $id = (int) $id;
        if (!$id) { redirect(admin_url('pitchsnap/websites')); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        $lead_url = admin_url('pitchsnap/lead/' . (int) $website->lead_id);
        if (!get_option('pitchsnap_anthropic_api_key')) { set_alert('danger', 'Anthropic API key not configured. Go to ClickFuzz Web → Settings.'); redirect($lead_url); }
        if ($website->status !== 'failed') { set_alert('warning', 'Anthropic retry is only available for failed websites.'); redirect($lead_url); }
        $queued = $this->pitchsnap_model->queue_for_generation_with_provider($id, 'anthropic');
        if (!$queued) { set_alert('danger', 'Could not queue for Anthropic retry. Please try again.'); redirect($lead_url); }
        log_activity('ClickFuzz Web: Queued for Anthropic retry [Website ID: ' . $id . ']');
        set_alert('success', 'Website #' . $id . ' queued for Anthropic generation.');
        redirect($lead_url);
    }

    public function delete_preview($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if (!$this->input->post()) { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        $detail_url = admin_url('pitchsnap/detail/' . $id);
        $token      = $website->preview_token ?? '';
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) { set_alert('danger', 'Invalid preview token for this website.'); redirect($detail_url); }
        $base_dir    = dirname(FCPATH) . '/previews';
        $preview_dir = $base_dir . '/' . $token;
        $real_base = realpath($base_dir);
        if (!$real_base) { set_alert('danger', 'Preview directory root not found.'); redirect($detail_url); }
        $note = null;
        if (!is_dir($preview_dir)) {
            $note = 'Preview files were already removed.';
        } else {
            $real_dir = realpath($preview_dir);
            if (!$real_dir || strpos($real_dir . '/', $real_base . '/') !== 0) { set_alert('danger', 'Security error: preview path resolves outside allowed directory.'); redirect($detail_url); }
            $entries = @scandir($preview_dir) ?: [];
            $blocked = false;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $fp = $preview_dir . '/' . $entry;
                if (is_dir($fp)) { $blocked = true; break; }
                @unlink($fp);
            }
            if ($blocked) { set_alert('danger', 'Preview directory contains unexpected subdirectories. Manual cleanup required.'); redirect($detail_url); }
            @rmdir($preview_dir);
        }
        $this->pitchsnap_model->clear_preview_url($id);
        log_activity('ClickFuzz Web: Preview deleted [Website ID: ' . $id . ']');
        if ($note) { set_alert('warning', 'Preview record cleared. ' . $note); }
        else { set_alert('success', 'Preview deleted. The website record is preserved in ClickFuzz Web history.'); }
        redirect($detail_url);
    }

    public function edit_html($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $id = (int) $id;
        if (!$id) { redirect(admin_url('pitchsnap/websites')); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        if (empty($website->generation_result)) {
            set_alert('warning', 'No generated HTML is stored for this website.');
            redirect(admin_url('pitchsnap/detail/' . $id));
        }

        if ($this->input->post()) {
            $html = $this->input->post('html', false);
            if (empty(trim($html))) { set_alert('danger', 'HTML cannot be empty.'); redirect(admin_url('pitchsnap/edit_html/' . $id)); }

            if (stripos($html, 'noindex') === false) {
                $meta = '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">';
                if (stripos($html, '</head>') !== false) { $html = str_ireplace('</head>', $meta . "\n</head>", $html); }
            }

            if (!function_exists('clickfuzz_web_runtime_script_tag')) { require_once FCPATH . 'modules/pitchsnap/pitchsnap.php'; }
            if (stripos($html, 'pitchsnap/runtime.js') === false) {
                $site   = $this->pitchsnap_model->get_site_by_website_id($id);
                $widget = clickfuzz_web_runtime_script_tag($website->preview_token, $site ? $site->site_token : '');
                if (stripos($html, '</body>') !== false) { $html = str_ireplace('</body>', $widget . "\n</body>", $html); }
                else { $html .= "\n" . $widget; }
            }

            $this->pitchsnap_model->save_html($id, $html);
            if (!function_exists('clickfuzz_web_deploy_preview')) { require_once FCPATH . 'modules/pitchsnap/pitchsnap.php'; }
            $deploy = clickfuzz_web_deploy_preview($website->preview_token, $html);
            if ($deploy['success']) {
                if (($website->preview_url ?? '') !== $deploy['url']) { $this->pitchsnap_model->set_preview_url($id, $deploy['url']); }
                log_activity('ClickFuzz Web: HTML edited and redeployed [Website ID: ' . $id . ']');
                set_alert('success', 'HTML saved and preview updated.');
            } else {
                log_activity('ClickFuzz Web: HTML edited but deploy failed [Website ID: ' . $id . '] ' . $deploy['error']);
                set_alert('warning', 'HTML saved to database but preview deploy failed: ' . $deploy['error']);
            }
            redirect(admin_url('pitchsnap/detail/' . $id));
        }

        $this->load->model('leads_model');
        $lead = $this->leads_model->get($website->lead_id);
        $data['title']   = 'Edit HTML — Website #' . $id;
        $data['website'] = $website;
        $data['lead']    = $lead;
        $this->load->view('pitchsnap/admin_edit_html', $data);
    }

    public function modify_html($id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if (!$this->input->post()) { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }
        $id = (int) $id;
        if (!$id) { return $this->_json(['success' => false, 'message' => 'Invalid website ID.']); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { return $this->_json(['success' => false, 'message' => 'Website not found.']); }
        if (empty($website->generation_result)) { return $this->_json(['success' => false, 'message' => 'No generated HTML found for this website.']); }

        $modification_request = trim($this->input->post('modification_request', true));
        if (empty($modification_request)) { return $this->_json(['success' => false, 'message' => 'Please describe the changes you want to make.']); }
        if (!$this->pitchsnap_model->lock_for_modification($id)) { return $this->_json(['success' => false, 'message' => 'A modification is already in progress for this website. Please wait.']); }

        set_time_limit(350);

        if (!class_exists('Pitchsnap_anthropic')) { require_once FCPATH . 'modules/pitchsnap/libraries/Pitchsnap_anthropic.php'; }
        $anthropic = new Pitchsnap_anthropic();

        $prompt = "You are modifying an existing website, not redesigning it from scratch.\n\n"
                . "Preserve all existing layout, styling, content, sections, images, functionality, "
                . "responsive behavior, and integrations except where directly necessary to satisfy "
                . "the requested changes.\n\n"
                . "Make only the requested changes and any minimal supporting adjustments required "
                . "for them to work correctly.\n\n"
                . "Do not remove or rewrite unrelated sections.\n\n"
                . "Do not invent new business facts.\n\n"
                . "Preserve any <span data-pitchsnap-current-year></span> elements exactly as-is; "
                . "do not replace them with a hardcoded year unless the administrator has specifically "
                . "requested removal of the copyright.\n\n"
                . "Return the complete updated HTML document only, starting with <!DOCTYPE html> "
                . "and ending with </html>.\n\n"
                . "REQUESTED CHANGES:\n" . $modification_request . "\n\n"
                . "CURRENT HTML:\n" . $website->generation_result;

        $result = $anthropic->generate($prompt);

        if (!$result['success']) {
            $this->pitchsnap_model->unlock_modification($id);
            log_activity('ClickFuzz Web: Modification failed [Website ID: ' . $id . '] ' . $result['error']);
            return $this->_json(['success' => false, 'message' => 'AI modification failed: ' . $result['error']]);
        }

        $html = $result['result'];
        if (preg_match('/^```(?:html)?\s*([\s\S]+?)\s*```$/i', trim($html), $m)) { $html = $m[1]; }

        // Strip all external scripts, re-inject canonical ClickFuzz Web widget with site_token
        $_ps_color = '';
        if (preg_match('/<script[^>]+pitchsnap\/runtime\.js[^>]*\bdata-primary-color=["\']([ -~]{1,20})["\'"][^>]*>/i', $html, $_m)) {
            $_c = trim($_m[1]);
            if (preg_match('/^#[0-9A-Fa-f]{3}$|^#[0-9A-Fa-f]{6}$/', $_c)) { $_ps_color = $_c; }
        }
        $html = preg_replace('/<script[^>]+src=["\'][^"\']*["\'][^>]*>\s*<\/script>/i', '', $html);
        if (!function_exists('clickfuzz_web_runtime_script_tag')) { require_once FCPATH . 'modules/pitchsnap/pitchsnap.php'; }
        $site   = $this->pitchsnap_model->get_site_by_website_id($id);
        $widget = clickfuzz_web_runtime_script_tag($website->preview_token, $site ? $site->site_token : '', $_ps_color);
        if (stripos($html, '</body>') !== false) { $html = str_ireplace('</body>', $widget . "\n</body>", $html); }
        else { $html .= "\n" . $widget; }

        if (stripos($html, 'noindex') === false) {
            $meta = '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">';
            if (stripos($html, '</head>') !== false) { $html = str_ireplace('</head>', $meta . "\n</head>", $html); }
        }

        if (empty(trim($html)) || stripos($html, '<html') === false || stripos($html, '</html>') === false || strlen($html) < 500 || stripos($html, 'pitchsnap/runtime.js') === false) {
            $this->pitchsnap_model->unlock_modification($id);
            return $this->_json(['success' => false, 'message' => 'AI returned an incomplete or invalid HTML document.']);
        }

        $this->pitchsnap_model->save_html($id, $html);
        if (!function_exists('clickfuzz_web_deploy_preview')) { require_once FCPATH . 'modules/pitchsnap/pitchsnap.php'; }
        $deploy = clickfuzz_web_deploy_preview($website->preview_token, $html);
        if ($deploy['success'] && ($website->preview_url ?? '') !== $deploy['url']) { $this->pitchsnap_model->set_preview_url($id, $deploy['url']); }
        $this->pitchsnap_model->unlock_modification($id);

        if (!$deploy['success']) {
            log_activity('ClickFuzz Web: Modification saved but deploy failed [Website ID: ' . $id . '] ' . $deploy['error']);
            return $this->_json(['success' => true, 'message' => 'Changes saved but preview could not be redeployed: ' . $deploy['error']]);
        }

        log_activity('ClickFuzz Web: HTML modification applied [Website ID: ' . $id . ']');
        return $this->_json(['success' => true, 'message' => 'Changes applied and preview updated.']);
    }

    private function _json($data)
    {
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }
}
