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
        if ($primary && empty($primary->is_primary)) {
            $this->pitchsnap_model->set_primary_version($primary->id, $lead_id);
            $primary->is_primary = 1;
        }
        $versions = $this->pitchsnap_model->get_versions_for_lead($lead_id);
        $website  = $primary ?: $entry;
        $this->load->model('leads_model');
        $lead = $this->leads_model->get($lead_id);
        $data['title']         = 'Websites — ' . ($lead ? e($lead->name) : 'Lead #' . $lead_id);
        $data['website']       = $website;
        $data['versions']      = $versions;
        $data['lead']          = $lead;
        $data['conversations'] = $this->pitchsnap_model->get_conversations($website->id);
        $data['site']          = $this->pitchsnap_model->get_site_by_website_id((int) $entry->id)
                               ?: $this->pitchsnap_model->get_site_by_lead_id($lead_id);
        $data['agreement']     = !empty($data['site']) ? $this->pitchsnap_model->get_agreement_by_site($data['site']->id) : null;
        if (!empty($data['site'])) {
            $this->load->model('pitchsnap_ghl_model');
            $data['ghl_link'] = $this->pitchsnap_ghl_model->get_by_site($data['site']->id);
        } else {
            $data['ghl_link'] = null;
        }
        $data['is_published'] = $this->pitchsnap_model->is_site_published($data['site']);
        $data['site_data']         = !empty($data['site']) ? $this->pitchsnap_model->get_site_data($data['site']->id) : [];
        $data['onboarding_links']  = !empty($data['site']) ? $this->pitchsnap_model->get_onboarding_links_for_site($data['site']->id) : [];
        $data['all_flows']         = $this->pitchsnap_model->get_all_flows();
        if ($data['is_published']) {
            $data['pages']      = $this->pitchsnap_model->get_pages_for_site($data['site']->id, true);
            $data['site_media'] = $this->pitchsnap_model->get_media_for_site($data['site']->id);
        } else {
            $data['pages']      = [];
            $data['site_media'] = [];
        }
        $data['forms']                  = !empty($data['site']) ? $this->pitchsnap_model->get_forms_for_site($data['site']->id) : [];
        $data['site_pages_for_forms']   = !empty($data['site']) ? $this->pitchsnap_model->get_pages_for_site($data['site']->id) : [];
        $data['custom_domain']   = !empty($data['site']) ? $this->pitchsnap_model->get_custom_domain_for_site($data['site']->id) : null;
        $data['platform_domain'] = !empty($data['site']) ? $this->pitchsnap_model->get_platform_domain_for_site($data['site']->id) : null;
        $data['dns_status'] = null;
        $data['ssl_status'] = null;
        if (!empty($data['custom_domain'])) {
            $cd      = $data['custom_domain'];
            $_h      = $cd->hostname;
            $is_apex = (substr_count($_h, '.') === 1);

            $apex_dns_ok = true; // N/A for non-apex
            $www_dns_ok  = false;

            if ($is_apex) {
                $apex_dns_ok = false;
                foreach ((array) @dns_get_record($_h, DNS_A) as $_r) {
                    if (($_r['ip'] ?? '') === '164.90.255.122') { $apex_dns_ok = true; break; }
                }
            }
            $www_host = $is_apex ? 'www.' . $_h : $_h;
            foreach ((array) @dns_get_record($www_host, DNS_CNAME) as $_r) {
                if (rtrim(strtolower($_r['target'] ?? ''), '.') === 'customers.clickfuzz.com') { $www_dns_ok = true; break; }
            }

            if ($apex_dns_ok && $www_dns_ok)        { $data['dns_status'] = 'connected'; }
            elseif (!$apex_dns_ok && !$www_dns_ok)  { $data['dns_status'] = '@/www_invalid'; }
            elseif (!$apex_dns_ok)                  { $data['dns_status'] = '@_invalid'; }
            else                                    { $data['dns_status'] = 'www_invalid'; }

            $apex_ssl_ok = !$is_apex || (!empty($cd->apex_status) && $cd->apex_status === 'connected');
            $www_ssl_ok  = (!empty($cd->cf_status) && $cd->cf_status === 'connected');

            if ($apex_ssl_ok && $www_ssl_ok)        { $data['ssl_status'] = 'connected'; }
            elseif (!$apex_ssl_ok && !$www_ssl_ok)  { $data['ssl_status'] = '@/www_invalid'; }
            elseif (!$apex_ssl_ok)                  { $data['ssl_status'] = '@_invalid'; }
            else                                    { $data['ssl_status'] = 'www_invalid'; }
        }
        $this->load->view('admin_detail', $data);
    }

    public function approve_design($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $website    = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-pages';

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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid ID.'); redirect(admin_url('pitchsnap/websites')); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        $this->pitchsnap_model->set_primary_version($id, $website->lead_id);
        log_activity('ClickFuzz Web: Version set as primary [Website ID: ' . $id . ']');
        set_alert('success', 'Version #' . $id . ' is now the primary version.');
        redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-pages');
    }

    public function delete_versions()
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
        $ids         = $this->input->post('ids');
        $redirect_id = (int) $this->input->post('redirect_id');
        if (!is_array($ids) || empty($ids)) {
            set_alert('warning', 'No versions selected.');
            redirect(admin_url('pitchsnap/detail/' . $redirect_id) . '#tab-pages');
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
        redirect(admin_url('pitchsnap/detail/' . $redirect_id) . '#tab-pages');
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
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) {
            set_alert('danger', 'Invalid website ID.');
            redirect(admin_url('pitchsnap/websites'));
        }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) {
            $entry = $this->pitchsnap_model->get($id);
            if ($entry && $entry->lead_id) {
                $site = $this->pitchsnap_model->get_site_by_lead_id($entry->lead_id);
            }
        }
        if (!$site) {
            set_alert('danger', 'No site record found. Trigger a generation first so ClickFuzz Web can create the site record.');
            redirect($detail_url);
        }
        if (!function_exists('clickfuzz_web_publish_site')) {
            require_once FCPATH . 'modules/pitchsnap/pitchsnap.php';
        }
        $result = clickfuzz_web_publish_site($site->id);
        if ($result['success']) {
            if (!function_exists('clickfuzz_web_cleanup_generation_history')) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
            }
            $website = $this->pitchsnap_model->get($id);
            if ($website) { clickfuzz_web_cleanup_generation_history((int) $website->lead_id); }
            log_activity('ClickFuzz Web: Site published [Site ID: ' . $site->id . ', URL: ' . $result['url'] . ']');
            set_alert('success', 'Site published at <a href="' . $result['url'] . '" target="_blank">' . $result['url'] . '</a>');
        } else {
            set_alert('danger', 'Publish failed: ' . $result['error']);
        }
        redirect($detail_url);
    }

    public function save_publish_type($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) { set_alert('danger', 'No site record found.'); redirect($detail_url); }
        if ($this->pitchsnap_model->is_site_published($site)) { set_alert('danger', 'Publishing method is locked after a successful publish.'); redirect($detail_url); }
        $type = $this->input->post('publish_type', true);
        if (!in_array($type, ['html', 'wordpress'])) { set_alert('danger', 'Invalid publish type.'); redirect($detail_url); }
        $this->pitchsnap_model->update_site($site->id, ['publish_type' => $type]);
        redirect($detail_url);
    }

    public function save_wp_connection($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) { set_alert('danger', 'No site record found.'); redirect($detail_url); }

        $wp_url = rtrim(trim((string) $this->input->post('wp_site_url', true)), '/');
        if (!empty($wp_url) && !filter_var($wp_url, FILTER_VALIDATE_URL)) {
            set_alert('danger', 'Invalid WordPress site URL.');
            redirect($detail_url);
        }

        $updates = ['wp_site_url' => $wp_url];
        $raw_user = trim((string) $this->input->post('wp_username', true));
        if ($raw_user !== '') { $updates['wp_username'] = $raw_user; }
        $raw_pass = (string) $this->input->post('wp_app_password', false);
        if ($raw_pass !== '' && $raw_pass !== '••••••••') {
            $updates['wp_app_password'] = trim($raw_pass);
        }

        $this->pitchsnap_model->update_site($site->id, $updates);
        log_activity('ClickFuzz Web: WordPress connection saved [Site ID: ' . $site->id . ']');
        set_alert('success', 'WordPress connection saved.');
        redirect($detail_url);
    }

    public function publish_site_wp($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) { set_alert('danger', 'No site record found.'); redirect($detail_url); }
        if (!function_exists('clickfuzz_web_publish_site_wp')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
        }
        $result = clickfuzz_web_publish_site_wp($site->id);
        if ($result['success']) {
            if (!function_exists('clickfuzz_web_cleanup_generation_history')) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
            }
            $website = $this->pitchsnap_model->get($id);
            if ($website) { clickfuzz_web_cleanup_generation_history((int) $website->lead_id); }
            log_activity('ClickFuzz Web: Site published to WordPress [Site ID: ' . $site->id . ', URL: ' . $result['url'] . ']');
            set_alert('success', 'Site published to WordPress at <a href="' . e($result['url']) . '" target="_blank">' . e($result['url']) . '</a>');
        } else {
            set_alert('danger', 'WordPress publish failed: ' . $result['error']);
        }
        redirect($detail_url);
    }

    // ── WordPress Connector ───────────────────────────────────────────────────

    public function generate_wp_token($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) { set_alert('danger', 'No site record found for this website.'); redirect(admin_url('pitchsnap/websites')); }

        $this->pitchsnap_model->generate_wp_pairing_token($site->id);
        log_activity('ClickFuzz Web: WordPress pairing token generated [Website ID: ' . $id . ']');
        redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-publishing');
    }

    public function download_wp_plugin($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $id = (int) $id;
        if (!$id || !$this->pitchsnap_model->get($id)) { show_404(); }

        $plugin_src = FCPATH . 'wp-plugin/clickfuzz-connector/';
        if (!is_dir($plugin_src)) {
            set_alert('danger', 'Plugin package not found. Contact support.');
            redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-publishing');
        }

        if (!class_exists('ZipArchive')) {
            set_alert('danger', 'ZIP support unavailable on this server.');
            redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-publishing');
        }

        $tmp = sys_get_temp_dir() . '/clickfuzz-connector-' . time() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            set_alert('danger', 'Failed to create plugin ZIP.');
            redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-publishing');
        }

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($plugin_src, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iter as $file) {
            $relative = 'clickfuzz-connector/' . ltrim(str_replace($plugin_src, '', $file->getRealPath()), '/\\');
            $zip->addFile($file->getRealPath(), $relative);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="clickfuzz-connector.zip"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-cache');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public function reset_wp_connector($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        if (!$this->pitchsnap_model->get($id)) { show_404(); }
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) { set_alert('danger', 'No site record found for this website.'); redirect(admin_url('pitchsnap/websites')); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';

        $this->pitchsnap_model->clear_wp_connector($site->id);
        log_activity('ClickFuzz Web: WordPress connector reset [Website ID: ' . $id . ']');
        set_alert('success', 'WordPress connector reset. Re-pair from the plugin settings page.');
        redirect($detail_url);
    }

    public function test_wp_connection($id = '')
    {
        if (!is_admin()) { $this->_json(['success' => false, 'error' => 'Access denied.']); return; }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') { $this->_json(['success' => false, 'error' => 'POST required.']); return; }
        $id = (int) $id;
        if (!$id) { $this->_json(['success' => false, 'error' => 'Invalid ID.']); return; }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { $this->_json(['success' => false, 'error' => 'Website not found.']); return; }
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) { $this->_json(['success' => false, 'error' => 'No site record found.']); return; }

        if (!function_exists('clickfuzz_web_wordpress_connector_request')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_wordpress_helper.php';
        }

        $r = clickfuzz_web_wordpress_connector_request($id, 'GET', '/clickfuzz/v1/status');
        if (!$r['success']) {
            $this->_json(['success' => false, 'error' => $r['error']]);
            return;
        }

        $body = (array) $r['body'];
        $this->pitchsnap_model->save_wp_status($site->id, [
            'wp_connected_at'      => date('Y-m-d H:i:s'),
            'wp_connector_version' => $body['version']           ?? null,
            'wp_wp_version'        => $body['wp']                ?? null,
            'wp_active_theme_slug' => $body['active_theme_slug'] ?? null,
        ]);

        $this->_json([
            'success'           => true,
            'connector_version' => $body['version']           ?? '',
            'wp_version'        => $body['wp']                ?? '',
            'active_theme_slug' => $body['active_theme_slug'] ?? '',
            'active_theme_name' => $body['active_theme_name'] ?? '',
        ]);
    }

    public function deploy_to_wordpress($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        if (!$this->pitchsnap_model->get($id)) { show_404(); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';

        if (!function_exists('clickfuzz_web_deploy_to_wordpress')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_wordpress_helper.php';
        }

        $result = clickfuzz_web_deploy_to_wordpress($id);

        if ($result['success']) {
            set_alert('success', 'WordPress deployment complete.');
        } else {
            set_alert('danger', 'Deployment failed at step "' . (end($result['steps'])['label'] ?? '?') . '": ' . $result['error']);
        }
        $this->session->set_flashdata('wp_deploy_result', json_encode($result));
        redirect($detail_url);
    }

    public function redeploy_wp_theme($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        if (!$this->pitchsnap_model->get($id)) { show_404(); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';

        if (!function_exists('clickfuzz_web_redeploy_wp_theme')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_wordpress_helper.php';
        }

        $result = clickfuzz_web_redeploy_wp_theme($id);

        if ($result['success']) {
            set_alert('success', 'WordPress theme redeployed.');
        } else {
            set_alert('danger', 'Theme redeploy failed: ' . $result['error']);
        }
        $this->session->set_flashdata('wp_deploy_result', json_encode($result));
        redirect($detail_url);
    }

    public function reimport_wp_content($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        if (!$this->pitchsnap_model->get($id)) { show_404(); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';

        if (!function_exists('clickfuzz_web_reimport_wp_content')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_wordpress_helper.php';
        }

        $result = clickfuzz_web_reimport_wp_content($id);

        if ($result['success']) {
            set_alert('success', 'WordPress content reimported.');
        } else {
            set_alert('danger', 'Content reimport failed: ' . $result['error']);
        }
        $this->session->set_flashdata('wp_deploy_result', json_encode($result));
        redirect($detail_url);
    }

    public function export_wordpress($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        if (empty($website->generation_result)) {
            set_alert('danger', 'No generated HTML found. Generate the site first.');
            redirect($detail_url);
        }
        if (!function_exists('clickfuzz_web_export_wordpress_site')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_wordpress_helper.php';
        }
        $result = clickfuzz_web_export_wordpress_site($id);
        if ($result['success']) {
            $warnings = !empty($result['warnings']) ? ' Warnings: ' . implode('; ', $result['warnings']) : '';
            log_activity('ClickFuzz Web: WordPress export created [Website ID: ' . $id . ']');
            set_alert('success', 'WordPress package exported.' . $warnings);
        } else {
            set_alert('danger', 'WordPress export failed: ' . $result['error']);
        }
        redirect($detail_url);
    }

    public function download_wordpress($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $id = (int) $id;
        if (!$id) { show_404(); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }

        $export_dir = dirname(FCPATH) . '/exports/wordpress/' . $id;
        $real_base  = realpath(dirname(FCPATH) . '/exports/wordpress');
        if (!$real_base) { show_404(); }

        $zips = glob($export_dir . '/*.zip') ?: [];
        if (empty($zips)) { show_404(); }

        $zip_path = $zips[0];
        $real_zip = realpath($zip_path);
        if (!$real_zip || strpos($real_zip, $real_base . '/') !== 0) { show_404(); }
        if (!is_file($real_zip)) { show_404(); }

        $filename = basename($real_zip);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($real_zip));
        readfile($real_zip);
        exit;
    }

    public function update_wp_plugin($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $id = (int) $id;
        if (!$id) { show_404(); }

        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';

        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site || empty($site->wp_api_key)) {
            $this->session->set_flashdata('wp_update_result', json_encode([
                'success' => false,
                'error'   => 'WordPress Connector is not connected.',
                'version' => null,
            ]));
            redirect($detail_url);
            return;
        }

        if (!function_exists('clickfuzz_web_update_wp_plugin')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_wordpress_helper.php';
        }
        $result = clickfuzz_web_update_wp_plugin($id);

        $this->session->set_flashdata('wp_update_result', json_encode([
            'success' => $result['success'],
            'error'   => $result['success'] ? null : ($result['error'] ?? 'Update failed.'),
            'version' => $result['body']['version'] ?? null,
        ]));

        redirect($detail_url);
    }

    public function save_custom_domain($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) {
            set_alert('danger', 'Invalid website ID.');
            redirect(admin_url('pitchsnap/websites'));
        }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';
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

        if (!function_exists('clickfuzz_web_cf_provision_hostname')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_cloudflare_helper.php';
        }

        $old_domain = $this->pitchsnap_model->get_custom_domain_for_site($site->id);
        $old_cf_id  = $old_domain ? ($old_domain->cf_hostname_id ?? null) : null;

        $cf_hostname_id = null;
        $cf_status      = null;
        $cf_hostname    = clickfuzz_web_cf_canonical_hostname($hostname);

        if ($cf_hostname) {
            $domain_unchanged = $old_domain && $old_domain->hostname === $hostname && $old_cf_id;
            if ($domain_unchanged) {
                // Same domain re-submitted — reuse existing CF record, recheck status
                $cf_hostname_id = $old_cf_id;
                $check          = clickfuzz_web_cf_check_hostname($old_cf_id);
                $cf_status      = $check['status'];
            } else {
                if ($old_cf_id && $old_domain && $old_domain->hostname !== $hostname) {
                    clickfuzz_web_cf_delete_hostname($old_cf_id);
                }
                $cf_result = clickfuzz_web_cf_provision_hostname($cf_hostname);
                if ($cf_result['success']) {
                    $cf_hostname_id = $cf_result['cf_hostname_id'];
                    $cf_status      = $cf_result['cf_status'];
                    $this->pitchsnap_model->create_log('cloudflare', 'Provisioned ' . $cf_hostname . ' (' . $cf_status . ')', ['cf_hostname_id' => $cf_hostname_id, 'site_id' => $site->id]);
                } else {
                    $cf_status = 'failed';
                    $this->pitchsnap_model->create_log('cloudflare', 'Provision failed: ' . $cf_hostname, ['error' => $cf_result['error'], 'site_id' => $site->id]);
                    log_activity('ClickFuzz Web: CF provision failed [Site: ' . $site->id . ', Hostname: ' . $cf_hostname . '] ' . $cf_result['error']);
                }
            }
        }

        // Apex provisioning (apex domains only)
        $apex_status = null;
        if (!function_exists('clickfuzz_web_hostname_is_apex')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_dns_helper.php';
        }
        if (clickfuzz_web_hostname_is_apex($hostname)) {
            if (!function_exists('clickfuzz_web_apex_provision')) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_apex_helper.php';
            }
            $domain_unchanged = $old_domain && $old_domain->hostname === $hostname
                && !empty($old_domain->apex_status) && $old_domain->apex_status !== 'failed';
            if ($domain_unchanged) {
                $apex_status = $old_domain->apex_status;
            } else {
                $apex_result = clickfuzz_web_apex_provision($hostname);
                if ($apex_result['success']) {
                    $apex_status = 'pending';
                } else {
                    log_activity('ClickFuzz Web: Apex provision failed [Site: ' . $site->id . ', Hostname: ' . $hostname . '] ' . ($apex_result['error'] ?? ''));
                }
            }
        }

        $result = $this->pitchsnap_model->save_custom_domain($site->id, $hostname, $cf_hostname_id, $cf_status, $apex_status);
        if ($result) {
            log_activity('ClickFuzz Web: Custom domain saved [Site ID: ' . $site->id . ', Hostname: ' . $hostname . ']');
            if ($cf_status === 'failed') {
                set_alert('warning', 'Custom domain <strong>' . e($hostname) . '</strong> saved, but Cloudflare provisioning failed. Check API settings.');
            } else {
                set_alert('success', 'Custom domain <strong>' . e($hostname) . '</strong> saved. DNS and Cloudflare setup pending.');
            }
        } else {
            set_alert('danger', 'Failed to save custom domain. Please try again.');
        }
        redirect($detail_url);
    }

    public function remove_custom_domain($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) {
            set_alert('danger', 'Invalid website ID.');
            redirect(admin_url('pitchsnap/websites'));
        }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) {
            set_alert('danger', 'No site record found.');
            redirect($detail_url);
        }

        $domain = $this->pitchsnap_model->get_custom_domain_for_site($site->id);
        if ($domain && !empty($domain->cf_hostname_id)) {
            if (!function_exists('clickfuzz_web_cf_delete_hostname')) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_cloudflare_helper.php';
            }
            clickfuzz_web_cf_delete_hostname($domain->cf_hostname_id);
        }

        // Best-effort apex removal — call for any apex hostname regardless of apex_status
        if ($domain && !empty($domain->hostname)) {
            if (!function_exists('clickfuzz_web_hostname_is_apex')) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_dns_helper.php';
            }
            if (clickfuzz_web_hostname_is_apex($domain->hostname)) {
                if (!function_exists('clickfuzz_web_apex_remove')) {
                    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_apex_helper.php';
                }
                clickfuzz_web_apex_remove($domain->hostname);
            }
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

    public function refresh_domain_status($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { redirect(admin_url('pitchsnap/websites')); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';

        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) { redirect($detail_url); }

        $domain = $this->pitchsnap_model->get_custom_domain_for_site($site->id);
        if (!$domain) { redirect($detail_url); }

        $changed = [];

        if (!empty($domain->cf_hostname_id)) {
            if (!function_exists('clickfuzz_web_cf_check_hostname')) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_cloudflare_helper.php';
            }
            $cf_result = clickfuzz_web_cf_check_hostname($domain->cf_hostname_id);
            if ($cf_result['status'] !== ($domain->cf_status ?? '')) {
                $this->pitchsnap_model->update_cf_status($domain->id, $cf_result['status']);
                $changed[] = 'Cloudflare → ' . $cf_result['status'];
            }
        }

        if (!empty($domain->hostname) && !empty($domain->apex_status) && $domain->apex_status !== 'connected') {
            if (!function_exists('clickfuzz_web_hostname_is_apex')) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_dns_helper.php';
            }
            if (clickfuzz_web_hostname_is_apex($domain->hostname)) {
                if (!function_exists('clickfuzz_web_apex_status_check')) {
                    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_apex_helper.php';
                }
                $apex_result = clickfuzz_web_apex_status_check($domain->hostname);
                if ($apex_result['success'] && $apex_result['status'] === 'connected') {
                    $this->pitchsnap_model->update_apex_status($domain->id, 'connected');
                    $changed[] = 'Apex SSL → connected';
                }
            }
        }

        if ($changed) {
            set_alert('success', 'Status updated: ' . implode(', ', $changed) . '.');
        } else {
            set_alert('info', 'Statuses checked — no changes.');
        }
        redirect($detail_url);
    }

    public function verify_custom_domain($id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) {
            set_alert('danger', 'Invalid website ID.');
            redirect(admin_url('pitchsnap/websites'));
        }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-publishing';
        $site = $this->pitchsnap_model->get_site_by_website_id($id);
        if (!$site) {
            set_alert('danger', 'No site record found.');
            redirect($detail_url);
        }
        $cd = $this->pitchsnap_model->get_custom_domain_for_site($site->id);
        if (!$cd) {
            set_alert('warning', 'No custom domain is set for this site.');
            redirect($detail_url);
        }

        if (!function_exists('clickfuzz_web_verify_dns')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_dns_helper.php';
        }

        $result = clickfuzz_web_verify_dns($cd->hostname);

        if ($result['status'] === 'verified') {
            $this->pitchsnap_model->update_domain_verification(
                $cd->id, 'verified', date('Y-m-d H:i:s')
            );
            log_activity('ClickFuzz Web: Custom domain verified [Site ID: ' . $site->id . ', Hostname: ' . $cd->hostname . ']');
            set_alert('success', '<strong>' . e($cd->hostname) . '</strong> DNS verified successfully.');
        } elseif ($result['status'] === 'failed') {
            $this->pitchsnap_model->update_domain_verification(
                $cd->id, 'failed', null
            );
            set_alert('danger', 'DNS check failed for <strong>' . e($cd->hostname) . '</strong>: ' . e($result['reason']));
        } else {
            // pending — do not overwrite a previously-verified status with pending
            if ($cd->verification_status !== 'verified') {
                $this->pitchsnap_model->update_domain_verification(
                    $cd->id, 'pending', null
                );
            }
            set_alert('warning', 'DNS not yet detected for <strong>' . e($cd->hostname) . '</strong>: ' . e($result['reason']));
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

        // Active onboarding flows for flow selector
        $data['active_flows'] = array_values(array_filter(
            $this->pitchsnap_model->get_all_flows(),
            function ($f) { return $f['status'] === 'active'; }
        ));

        // Build guardrail display values (saved DB value, falling back to hardcoded provider default)
        $gr_names        = ['logo_usage','image_selection','team_placement','team_association','anonymous_team','gallery_usage','credential_usage','owner_story','visual_readability','brand_color_preservation'];
        $manus_defaults  = array_fill_keys($gr_names, false);
        $manus_defaults['brand_color_preservation'] = true;
        $gr_defaults     = [
            'anthropic' => array_fill_keys($gr_names, true),
            'manus'     => $manus_defaults,
        ];
        $gr_values = [];
        foreach (['anthropic', 'manus'] as $prov) {
            foreach ($gr_names as $name) {
                $saved = get_option('pitchsnap_guardrail_' . $prov . '_' . $name);
                $gr_values[$prov][$name] = ($saved === false || $saved === '') ? $gr_defaults[$prov][$name] : (bool)(int)$saved;
            }
        }
        $data['guardrail_values']   = $gr_values;
        $data['ghl_destinations']   = $this->pitchsnap_model->get_all_global_ghl_destinations();

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
        if (array_key_exists('pitchsnap_general_submitted', $_POST)) {
        // ── Provider selection ────────────────────────────────────────────────
        $primary  = $this->input->post('pitchsnap_primary_provider', true);
        $fallback = $this->input->post('pitchsnap_fallback_provider', true);
        update_option('pitchsnap_primary_provider',  in_array($primary,  ['manus', 'anthropic']) ? $primary  : 'manus');
        update_option('pitchsnap_fallback_provider', in_array($fallback, ['none',  'anthropic']) ? $fallback : 'none');
        update_option('pitchsnap_ai_provider', $primary === 'anthropic' ? 'anthropic' : 'manus');

        // ── Manus credentials ─────────────────────────────────────────────────
        $manus_key = $this->input->post('pitchsnap_manus_api_key');
        if (!empty($manus_key)) { update_option('pitchsnap_manus_api_key', trim((string) $manus_key)); }
        update_option('pitchsnap_manus_prompt', $this->input->post('pitchsnap_manus_prompt'));

        // ── Anthropic credentials ─────────────────────────────────────────────
        $api_key = $this->input->post('pitchsnap_anthropic_api_key');
        $model   = trim((string) $this->input->post('pitchsnap_model', true));
        $prompt  = $this->input->post('pitchsnap_generation_prompt');
        if (!empty($api_key)) { update_option('pitchsnap_anthropic_api_key', trim((string) $api_key)); }
        update_option('pitchsnap_model',             $model ?: 'claude-sonnet-4-6');
        update_option('pitchsnap_generation_prompt', $prompt);

        // ── Generation guardrails ─────────────────────────────────────────────
        // hidden+checkbox pattern in view guarantees each key is always present in POST
        $gr_names     = ['logo_usage','image_selection','team_placement','team_association','anonymous_team','gallery_usage','credential_usage','owner_story','visual_readability','brand_color_preservation'];
        $gr_providers = ['anthropic','manus'];
        foreach ($gr_providers as $pkey) {
            foreach ($gr_names as $gkey) {
                $opt_key = 'pitchsnap_guardrail_' . $pkey . '_' . $gkey;
                update_option($opt_key, $this->input->post($opt_key) ? '1' : '0');
            }
        }

        // ── Operational ───────────────────────────────────────────────────────
        if (array_key_exists('pitchsnap_logging_enabled', $_POST)) {
            update_option('pitchsnap_logging_enabled', $this->input->post('pitchsnap_logging_enabled') ? '1' : '0');
        }
        update_option('pitchsnap_web_design_admin', (string)(int) $this->input->post('pitchsnap_web_design_admin', true));

        // ── GHL token & agency location ───────────────────────────────────────
        $ghl_key = trim((string) $this->input->post('pitchsnap_ghl_api_key'));
        if ($ghl_key !== '') { update_option('pitchsnap_ghl_api_key', $ghl_key); }
        update_option('pitchsnap_agency_location_id', trim((string) $this->input->post('pitchsnap_agency_location_id', true)));

        // ── Cloudflare ─────────────────────────────────────────────────────────
        $cf_token = trim((string) $this->input->post('pitchsnap_cf_api_token'));
        if ($cf_token !== '') { update_option('pitchsnap_cf_api_token', $cf_token); }
        $cf_zone = trim((string) $this->input->post('pitchsnap_cf_zone_id', true));
        if ($cf_zone !== '') { update_option('pitchsnap_cf_zone_id', $cf_zone); }

        // ── Apex API ───────────────────────────────────────────────────────────
        $apex_token = trim((string) $this->input->post('pitchsnap_apex_api_token'));
        if ($apex_token !== '') { update_option('pitchsnap_apex_api_token', $apex_token); }

        // ── Fields not yet in view — guard against absent POST keys ───────────
        if (array_key_exists('pitchsnap_video_demo_url', $_POST)) {
            update_option('pitchsnap_video_demo_url', trim((string) $this->input->post('pitchsnap_video_demo_url', true)));
        }
        if (array_key_exists('pitchsnap_agreement_version', $_POST)) {
            $v = trim((string) $this->input->post('pitchsnap_agreement_version', true));
            if ($v !== '') { update_option('pitchsnap_agreement_version', $v); }
            update_option('pitchsnap_agreement_text', $this->input->post('pitchsnap_agreement_text'));
        }
        if (array_key_exists('pitchsnap_onboarding_flow_id', $_POST)) {
            update_option('pitchsnap_onboarding_flow_id', (string)(int) $this->input->post('pitchsnap_onboarding_flow_id', true));
        }

        if (array_key_exists('pitchsnap_payment_type', $_POST)) {
            $payment_type = $this->input->post('pitchsnap_payment_type', true);
            update_option('pitchsnap_payment_type', in_array($payment_type, ['onetime', 'subscription']) ? $payment_type : 'onetime');
            $price = trim((string) $this->input->post('pitchsnap_price', true));
            if ($price !== '' && is_numeric($price) && (float) $price > 0) {
                update_option('pitchsnap_price', number_format((float) $price, 2, '.', ''));
            }
            update_option('pitchsnap_stripe_plan_id',   trim((string) $this->input->post('pitchsnap_stripe_plan_id', true)));
            $qty = (int) $this->input->post('pitchsnap_sub_quantity', true);
            update_option('pitchsnap_sub_quantity',     (string) max(1, $qty ?: 1));
            update_option('pitchsnap_sub_name',         trim((string) $this->input->post('pitchsnap_sub_name', true)));
            update_option('pitchsnap_sub_description',  $this->input->post('pitchsnap_sub_description'));
            update_option('pitchsnap_sub_include_desc', $this->input->post('pitchsnap_sub_include_desc') ? '1' : '0');
            $sub_cur = (int) $this->input->post('pitchsnap_sub_currency', true);
            if ($sub_cur > 0) { update_option('pitchsnap_sub_currency', (string) $sub_cur); }
            update_option('pitchsnap_sub_tax1',         trim((string) $this->input->post('pitchsnap_sub_tax1', true)));
            update_option('pitchsnap_sub_tax2',         trim((string) $this->input->post('pitchsnap_sub_tax2', true)));
        }
        } // end pitchsnap_general_submitted guard

        if (array_key_exists('pitchsnap_log_cats_submitted', $_POST)) {
            update_option('pitchsnap_logging_enabled', $this->input->post('pitchsnap_logging_enabled') ? '1' : '0');
            foreach (['stripe', 'sales', 'generation', 'ghl'] as $cat) {
                update_option('pitchsnap_log_' . $cat, $this->input->post('pitchsnap_log_' . $cat) ? '1' : '0');
            }
        }

        $tab = $this->input->post('active_tab', true);
        $tab = in_array($tab, ['general', 'logs']) ? $tab : 'general';
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
        } else {
            if (!get_option('pitchsnap_anthropic_api_key')) { return $this->_json(['success' => false, 'message' => 'Anthropic API key not configured. Go to ClickFuzz Web → Settings.']); }
        }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { return $this->_json(['success' => false, 'message' => 'Website not found.']); }
        // Block generation if the primary site is already published
        $primary_r = $this->pitchsnap_model->get_primary_for_lead((int) $website->lead_id);
        $check_id  = $primary_r ? $primary_r->id : $id;
        $pub_site  = $this->pitchsnap_model->get_site_by_website_id($check_id);
        if ($pub_site && $pub_site->status === 'published') {
            return $this->_json(['success' => false, 'message' => 'This site is already published. Generation is locked. A full redesign requires a new site record.']);
        }
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
        $original   = $this->pitchsnap_model->get($id);
        if (!$original) { show_404(); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-pages';
        $primary = get_option('pitchsnap_primary_provider') ?: 'manus';
        if ($primary === 'manus' && !get_option('pitchsnap_manus_api_key')) { set_alert('danger', 'Manus API key not configured. Go to ClickFuzz Web → Settings.'); redirect($detail_url); }
        if ($primary === 'anthropic' && !get_option('pitchsnap_anthropic_api_key')) { set_alert('danger', 'Anthropic API key not configured. Go to ClickFuzz Web → Settings.'); redirect($detail_url); }
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
        if (!$new_id) { set_alert('danger', 'Could not create new version record. Please try again.'); redirect($detail_url); }
        log_activity('ClickFuzz Web: Regeneration queued [New ID: ' . $new_id . ', From: ' . $id . ']');
        set_alert('success', 'New version queued for generation.');
        redirect(admin_url('pitchsnap/detail/' . $new_id) . '#tab-pages');
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
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }
        $id = (int) $id;
        if (!$id) { set_alert('danger', 'Invalid website ID.'); redirect(admin_url('pitchsnap/websites')); }
        $website = $this->pitchsnap_model->get($id);
        if (!$website) { show_404(); }
        $detail_url = admin_url('pitchsnap/detail/' . $id) . '#tab-pages';
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
            redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-pages');
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
            if (in_array($website->status, ['approved', 'sent', 'viewed'])) {
                $this->pitchsnap_model->update($id, ['status' => 'review_required']);
            }
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
            redirect(admin_url('pitchsnap/detail/' . $id) . '#tab-pages');
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
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }
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
        if (in_array($website->status, ['approved', 'sent', 'viewed'])) {
            $this->pitchsnap_model->update($id, ['status' => 'review_required']);
        }
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

    public function ghl_link_location($id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }
        $site_id     = (int) $id;
        $location_id = trim($this->input->post('ghl_location_id', true));
        if (!$site_id || $location_id === '') {
            return $this->_json(['success' => false, 'message' => 'Site ID and Location ID are required.']);
        }
        if (!get_option('pitchsnap_ghl_api_key')) {
            return $this->_json(['success' => false, 'message' => 'GHL Private Integration Token not configured. Go to Settings → GHL.']);
        }
        require_once FCPATH . 'modules/pitchsnap/libraries/Pitchsnap_ghl.php';
        $ghl    = new Pitchsnap_ghl();
        $result = $ghl->get_location($location_id);
        if (!$result['success']) {
            return $this->_json(['success' => false, 'message' => 'GHL verification failed: ' . $result['error']]);
        }
        $location_name = $result['data']['location']['name'] ?? $location_id;
        $this->load->model('pitchsnap_ghl_model');
        $this->pitchsnap_ghl_model->mark_connected($site_id, $location_id, $location_name);
        log_activity('ClickFuzz Web: GHL location linked [Site ID: ' . $site_id . ', Location: ' . $location_id . ']');
        return $this->_json(['success' => true, 'message' => 'Linked: ' . $location_name, 'location_name' => $location_name]);
    }

    public function ghl_test_connection($id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }
        $site_id = (int) $id;
        if (!$site_id) { return $this->_json(['success' => false, 'message' => 'Invalid site ID.']); }
        if (!get_option('pitchsnap_ghl_api_key')) {
            return $this->_json(['success' => false, 'message' => 'GHL Private Integration Token not configured. Go to Settings → GHL.']);
        }
        $this->load->model('pitchsnap_ghl_model');
        $ghl_link = $this->pitchsnap_ghl_model->get_by_site($site_id);
        if (!$ghl_link || empty($ghl_link->ghl_location_id)) {
            return $this->_json(['success' => false, 'message' => 'No GHL location linked for this site.']);
        }
        require_once FCPATH . 'modules/pitchsnap/libraries/Pitchsnap_ghl.php';
        $ghl    = new Pitchsnap_ghl();
        $result = $ghl->get_location($ghl_link->ghl_location_id);
        if (!$result['success']) {
            return $this->_json(['success' => false, 'message' => 'Connection test failed: ' . $result['error']]);
        }
        $location_name = $result['data']['location']['name'] ?? $ghl_link->ghl_location_id;
        $this->pitchsnap_ghl_model->mark_connected($site_id, $ghl_link->ghl_location_id, $location_name);
        return $this->_json(['success' => true, 'message' => 'Connection verified. Location: ' . $location_name]);
    }

    // -----------------------------------------------------------------------
    // Phase 3 — Pages
    // -----------------------------------------------------------------------

    // POST pitchsnap/page_add/{site_id}
    public function page_add($site_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $site_id = (int) $site_id;
        $site    = $this->pitchsnap_model->get_site_by_id($site_id);
        if (!$site || $site->status !== 'published') {
            set_alert('danger', 'Site not found or not published.');
            redirect(admin_url('pitchsnap/websites'));
        }

        $detail_url = admin_url('pitchsnap/detail/' . (int) $site->source_website_id);

        $title             = trim($this->input->post('title', true));
        $type              = trim($this->input->post('page_type', true));
        $slug              = trim($this->input->post('slug', true));
        $parent_id         = (int) $this->input->post('parent_page_id');
        $primary_keyword   = trim($this->input->post('primary_keyword', true));
        $supporting_kws    = trim($this->input->post('supporting_keywords', true));
        $instructions      = trim($this->input->post('instructions', true));
        $content_notes     = trim($this->input->post('content_notes', true));
        $video_url         = trim($this->input->post('video_url', true));
        $selected_media    = $this->input->post('selected_media') ?: [];

        if ($content_notes !== '') {
            $instructions = ($instructions !== '')
                ? "Content notes: {$content_notes}\n\n{$instructions}"
                : "Content notes: {$content_notes}";
        }

        $valid_types = ['homepage','about','service','service_area','contact','gallery','financing','faq','custom'];
        if ($title === '' || $slug === '' || !in_array($type, $valid_types, true)) {
            set_alert('danger', 'Page name, type, and slug are required.');
            redirect($detail_url);
        }

        // Sanitise slug
        $slug = strtolower(preg_replace('/[^a-z0-9\-]/', '', str_replace(' ', '-', $slug)));
        if ($slug === '') {
            set_alert('danger', 'Slug is invalid after sanitisation.');
            redirect($detail_url);
        }

        if (!$this->pitchsnap_model->page_slug_available($site_id, $slug)) {
            set_alert('danger', 'A page with that slug already exists on this site.');
            redirect($detail_url);
        }

        // Validate parent
        $validated_parent = null;
        if ($parent_id) {
            if ($this->pitchsnap_model->validate_page_parent(0, $parent_id, $site_id)) {
                $validated_parent = $parent_id;
            }
        }

        $page_data = [
            'title'               => $title,
            'slug'                => $slug,
            'page_type'           => $type,
            'parent_page_id'      => $validated_parent,
            'primary_keyword'     => $primary_keyword,
            'supporting_keywords' => $supporting_kws,
            'instructions'        => $instructions,
            'video_url'           => $video_url !== '' ? $video_url : null,
        ];

        $new_id = $this->pitchsnap_model->create_page($site_id, $page_data);

        if (!$new_id) {
            set_alert('danger', 'Failed to create page.');
            redirect($detail_url);
        }

        // Attach selected media in user-chosen order
        foreach (array_values($selected_media) as $order => $media_id) {
            $media_id = (int) $media_id;
            if ($media_id > 0) {
                $this->pitchsnap_model->attach_media_to_page($new_id, $media_id, $order);
            }
        }

        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_generation_helper.php';
        $queued = clickfuzz_web_queue_page_generation($new_id);

        log_activity('ClickFuzz Web: Page created [Page ID: ' . $new_id . ', Site: ' . $site_id . ']');

        if ($queued['success']) {
            set_alert('success', 'Page created — generation queued.');
        } else {
            set_alert('success', 'Page created. Queue it for generation from the page editor.');
        }
        redirect($detail_url . '#tab-pages');
    }

    // POST pitchsnap/page_media_upload/{site_id}  (AJAX)
    public function page_media_upload($site_id = '')
    {
        if (!is_admin()) { show_404(); }

        header('Content-Type: application/json');

        $site_id = (int) $site_id;
        $site    = $this->pitchsnap_model->get_site_by_id($site_id);
        if (!$site) {
            echo json_encode(['success' => false, 'error' => 'Site not found.']);
            return;
        }

        if (empty($_FILES['media_file']['tmp_name'])) {
            echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
            return;
        }

        $file          = $_FILES['media_file'];
        $original_name = $file['name'];
        $alt_text      = trim($this->input->post('alt_text', true));
        $category      = trim($this->input->post('category', true));
        $valid_cats    = ['logo','team','project','equipment','award','certification','before_after','general'];
        if (!in_array($category, $valid_cats, true)) { $category = 'general'; }

        $raw = file_get_contents($file['tmp_name']);
        $img = @imagecreatefromstring($raw);
        if (!$img) {
            echo json_encode(['success' => false, 'error' => 'Unsupported image format. Use JPEG, PNG, GIF, or BMP.']);
            return;
        }

        // Slugify alt text or original filename into base
        $base_source = $alt_text !== '' ? $alt_text : pathinfo($original_name, PATHINFO_FILENAME);
        $base        = strtolower(preg_replace('/-+/', '-', trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($base_source)), '-')));
        if ($base === '') { $base = 'image'; }

        $upload_dir = FCPATH . 'uploads/pitchsnap/media/' . $site_id . '/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Unique filename
        $filename = $base . '.webp';
        $i = 1;
        while (file_exists($upload_dir . $filename)) {
            $filename = $base . '-' . $i . '.webp';
            $i++;
        }

        // Convert to WebP at quality 82; reduce quality if >800 KB
        $quality = 82;
        ob_start(); imagewebp($img, null, $quality); $webp = ob_get_clean();
        while (strlen($webp) > 819200 && $quality > 40) {
            $quality -= 10;
            ob_start(); imagewebp($img, null, $quality); $webp = ob_get_clean();
        }

        // If still too large, scale dimensions down
        if (strlen($webp) > 819200) {
            $w     = imagesx($img);
            $h     = imagesy($img);
            $scale = sqrt(819200 / strlen($webp));
            $nw    = max(1, (int)($w * $scale));
            $nh    = max(1, (int)($h * $scale));
            $resized = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $resized;
            ob_start(); imagewebp($img, null, 82); $webp = ob_get_clean();
        }
        imagedestroy($img);

        if (file_put_contents($upload_dir . $filename, $webp) === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to save file.']);
            return;
        }

        $media_id = $this->pitchsnap_model->create_media($site_id, [
            'filename'          => $filename,
            'original_filename' => $original_name,
            'alt_text'          => $alt_text,
            'category'          => $category,
            'mime_type'         => 'image/webp',
            'file_size'         => strlen($webp),
        ]);

        if (!$media_id) {
            @unlink($upload_dir . $filename);
            echo json_encode(['success' => false, 'error' => 'Failed to save media record.']);
            return;
        }

        echo json_encode([
            'success'   => true,
            'media_id'  => $media_id,
            'filename'  => $filename,
            'url'       => base_url('uploads/pitchsnap/media/' . $site_id . '/' . rawurlencode($filename)),
            'alt_text'  => $alt_text,
            'csrf_hash' => $this->security->get_csrf_hash(),
        ]);
    }

    // GET pitchsnap/page_edit/{page_id}
    public function page_edit($page_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $page_id = (int) $page_id;
        $page    = $this->pitchsnap_model->get_page($page_id);
        if (!$page) { show_404(); }

        $site = $this->pitchsnap_model->get_site_by_id($page->site_id);
        if (!$site || $site->status !== 'published') {
            set_alert('danger', 'Site not accessible.');
            redirect(admin_url('pitchsnap/websites'));
        }

        $parent_options = $this->pitchsnap_model->get_active_pages_for_site($page->site_id);
        $page_media     = $this->pitchsnap_model->get_media_for_page($page_id);
        $site_media     = $this->pitchsnap_model->get_media_for_site($page->site_id);

        $missing = [];
        if (empty($page->title))        { $missing[] = 'Page Name'; }
        if (empty($page->slug))         { $missing[] = 'Slug'; }
        if (empty($page->page_type))    { $missing[] = 'Page Type'; }
        if (empty($page->primary_keyword) && empty($page->instructions)) {
            $missing[] = 'Primary Keyword or Generation Instructions';
        }
        $generate_ready = empty($missing);

        $data['title']          = 'Configure Page — ' . e($page->title);
        $data['page']           = $page;
        $data['site']           = $site;
        $data['detail_url']     = admin_url('pitchsnap/detail/' . (int) $site->source_website_id);
        $data['parent_options'] = $parent_options;
        $data['page_media']     = $page_media;
        $data['site_media']     = $site_media;
        $data['generate_ready'] = $generate_ready;
        $data['missing']        = $missing;
        $data['generations']    = $this->pitchsnap_model->get_page_generations($page_id);
        $data['current_gen']    = $this->pitchsnap_model->get_current_page_generation($page_id);

        // Phase 5: live URL + has-newer-generation flag for UI
        $live_url       = '';
        $has_newer_gen  = false;
        if ($page->status === 'published') {
            if ($site->publish_type === 'wordpress' && !empty($site->wp_site_url)) {
                $live_url = rtrim($site->wp_site_url, '/') . '/' . $page->slug . '/';
            } elseif (!empty($page->published_path)) {
                $domain_row = $this->pitchsnap_model->get_platform_domain_for_site($site->id);
                if ($domain_row) {
                    $live_url = 'https://' . $domain_row->hostname . '/' . $page->published_path . '/';
                }
            }
            if ($data['current_gen']) {
                $pub_gen_id    = (int) ($page->published_generation_id ?? 0);
                $has_newer_gen = $pub_gen_id > 0
                    ? ($pub_gen_id !== (int) $data['current_gen']->id)
                    : ($data['current_gen']->dateadded > $page->published_at);
            }
        }
        $data['live_url']      = $live_url;
        $data['has_newer_gen'] = $has_newer_gen;

        $this->load->view('admin_page_edit', $data);
    }

    // POST pitchsnap/page_save/{page_id}
    public function page_save($page_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $page_id = (int) $page_id;
        $page    = $this->pitchsnap_model->get_page($page_id);
        if (!$page) { show_404(); }

        $site = $this->pitchsnap_model->get_site_by_id($page->site_id);
        if (!$site || $site->status !== 'published') {
            set_alert('danger', 'Site not accessible.');
            redirect(admin_url('pitchsnap/websites'));
        }

        $edit_url = admin_url('pitchsnap/page_edit/' . $page_id);

        $title     = trim($this->input->post('title', true));
        $slug      = strtolower(preg_replace('/[^a-z0-9\-]/', '', str_replace(' ', '-', trim($this->input->post('slug', true)))));
        $type      = trim($this->input->post('page_type', true));
        $parent_id = (int) $this->input->post('parent_page_id');

        $valid_types = ['homepage','about','service','service_area','contact','gallery','financing','faq','custom'];
        if ($title === '' || $slug === '' || !in_array($type, $valid_types, true)) {
            set_alert('danger', 'Page name, type, and slug are required.');
            redirect($edit_url);
        }

        // Enforce homepage exclusivity: only one page per site may have type 'homepage'
        if ($type === 'homepage') {
            $existing_hp = $this->pitchsnap_model->get_homepage_page_for_site($page->site_id, $page_id);
            if ($existing_hp) {
                set_alert('danger', '"' . e($existing_hp->title) . '" is already assigned as the Homepage. Change its page type first.');
                redirect($edit_url);
            }
        }

        if (!$this->pitchsnap_model->page_slug_available($page->site_id, $slug, $page_id)) {
            set_alert('danger', 'A page with that slug already exists on this site.');
            redirect($edit_url);
        }

        $validated_parent = null;
        if ($parent_id) {
            if ($this->pitchsnap_model->validate_page_parent($page_id, $parent_id, $page->site_id)) {
                $validated_parent = $parent_id;
            }
        }

        $fields = [
            'title'               => $title,
            'slug'                => $slug,
            'page_type'           => $type,
            'parent_page_id'      => $validated_parent,
            'meta_title'          => trim($this->input->post('meta_title', true)) ?: null,
            'meta_description'    => trim($this->input->post('meta_description', true)) ?: null,
            'primary_keyword'     => trim($this->input->post('primary_keyword', true)) ?: null,
            'supporting_keywords' => trim($this->input->post('supporting_keywords', true)) ?: null,
            'instructions'        => trim($this->input->post('instructions', true)) ?: null,
            'noindex_page'        => $this->input->post('noindex_page') ? 1 : 0,
            'is_home_page'        => ($type === 'homepage') ? 1 : 0,
            'menu_primary'        => $this->input->post('menu_primary') ? 1 : 0,
            'menu_footer'         => $this->input->post('menu_footer') ? 1 : 0,
            'menu_label'          => trim($this->input->post('menu_label', true)) ?: null,
            'menu_order'          => max(0, (int) $this->input->post('menu_order')),
        ];

        $this->pitchsnap_model->update_page($page_id, $fields);
        log_activity('ClickFuzz Web: Page updated [Page ID: ' . $page_id . ']');

        // When setting as homepage, clear is_home_page on all other pages of this site
        if ($type === 'homepage') {
            $this->pitchsnap_model->clear_home_page_for_site($page->site_id, $page_id);
        }

        // Seed a generation record for homepage pages that have none yet
        if ($type === 'homepage' && !$this->pitchsnap_model->get_current_page_generation($page_id)) {
            $seed_html = $this->_resolve_site_preview_html($site);
            if ($seed_html) {
                $seed_gen_id = $this->pitchsnap_model->create_page_generation($page_id, $page->site_id, [
                    'html_content' => $seed_html,
                    'css_content'  => '',
                    'js_content'   => '',
                    'source'       => 'homepage_seed',
                ]);
                if ($seed_gen_id) {
                    $this->pitchsnap_model->set_current_page_generation($page_id, $seed_gen_id);
                    $this->pitchsnap_model->update_page($page_id, ['generation_status' => 'generated']);
                }
            }
        }

        // Auto-push to WordPress when already published
        $fresh_page   = $this->pitchsnap_model->get_page($page_id);
        $publish_type = $site->publish_type ?? 'html';
        if ($publish_type === 'wordpress' && !empty($fresh_page->wp_page_id)) {
            $gen = $this->pitchsnap_model->get_current_page_generation($page_id);
            if ($gen) {
                require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_publish_helper.php';
                $parent_wp_page_id = null;
                if (!empty($fresh_page->parent_page_id)) {
                    $parent_page = $this->pitchsnap_model->get_page((int) $fresh_page->parent_page_id);
                    if ($parent_page && !empty($parent_page->wp_page_id)) {
                        $parent_wp_page_id = (int) $parent_page->wp_page_id;
                    }
                }
                $push = clickfuzz_web_publish_page_wp($fresh_page, $site, $gen, $parent_wp_page_id, 0);
                if ($push['success']) {
                    $this->pitchsnap_model->publish_page($page_id, $fresh_page->published_path ?? '', $push['wp_page_id'] ?? null, null, null);
                    set_alert('success', 'Page saved and pushed to WordPress.');
                } else {
                    set_alert('warning', 'Page saved. WordPress push failed: ' . $push['error']);
                }
            } else {
                set_alert('success', 'Page saved.');
            }
        } else {
            set_alert('success', 'Page saved.');
        }
        redirect($edit_url);
    }

    // POST pitchsnap/page_trash/{page_id}
    public function page_trash($page_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $page_id = (int) $page_id;
        $page    = $this->pitchsnap_model->get_page($page_id);
        if (!$page) { show_404(); }

        $site       = $this->pitchsnap_model->get_site_by_id($page->site_id);
        $detail_url = $site ? admin_url('pitchsnap/detail/' . (int) $site->source_website_id) . '#tab-pages' : admin_url('pitchsnap/websites');

        $this->pitchsnap_model->trash_page($page_id);
        log_activity('ClickFuzz Web: Page trashed [Page ID: ' . $page_id . ']');
        set_alert('success', 'Page moved to trash.');
        redirect($detail_url);
    }

    // POST pitchsnap/page_restore/{page_id}
    public function page_restore($page_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $page_id = (int) $page_id;
        $page    = $this->pitchsnap_model->get_page($page_id);
        if (!$page) { show_404(); }

        $site       = $this->pitchsnap_model->get_site_by_id($page->site_id);
        $detail_url = $site ? admin_url('pitchsnap/detail/' . (int) $site->source_website_id) . '#tab-pages' : admin_url('pitchsnap/websites');

        $this->pitchsnap_model->restore_page($page_id);
        log_activity('ClickFuzz Web: Page restored [Page ID: ' . $page_id . ']');
        set_alert('success', 'Page restored.');
        redirect($detail_url);
    }

    // -----------------------------------------------------------------------
    // Phase 3 — Media Library
    // -----------------------------------------------------------------------

    // POST pitchsnap/media_upload/{site_id}
    public function media_upload($site_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $site_id = (int) $site_id;
        $site    = $this->pitchsnap_model->get_site_by_id($site_id);
        if (!$site || $site->status !== 'published') {
            set_alert('danger', 'Site not found or not published.');
            redirect(admin_url('pitchsnap/websites'));
        }

        $detail_url = admin_url('pitchsnap/detail/' . (int) $site->source_website_id) . '#tab-media';

        if (!function_exists('clickfuzz_web_upload_media')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_media_helper.php';
        }

        $result = clickfuzz_web_upload_media($site_id);
        if (!$result['success']) {
            set_alert('danger', 'Upload failed: ' . $result['error']);
            redirect($detail_url);
        }

        $alt_text = trim($this->input->post('alt_text', true));
        $category = trim($this->input->post('category', true));

        $valid_cats = ['logo','team','project','equipment','award','certification','before_after','general'];
        if (!in_array($category, $valid_cats, true)) { $category = 'general'; }

        $this->pitchsnap_model->create_media($site_id, [
            'filename'          => $result['filename'],
            'original_filename' => $result['original_filename'],
            'title'             => $alt_text !== '' ? $alt_text : $result['original_filename'],
            'alt_text'          => $alt_text !== '' ? $alt_text : null,
            'category'          => $category,
            'mime_type'         => $result['mime_type'],
            'file_size'         => $result['file_size'],
        ]);

        log_activity('ClickFuzz Web: Media uploaded [Site: ' . $site_id . ', File: ' . $result['filename'] . ']');
        set_alert('success', 'Media uploaded successfully.');
        redirect($detail_url);
    }

    // POST pitchsnap/media_save/{media_id}  — update metadata
    public function media_save($media_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $media_id = (int) $media_id;
        $media    = $this->pitchsnap_model->get_media($media_id);
        if (!$media) { show_404(); }

        $site       = $this->pitchsnap_model->get_site_by_id($media->site_id);
        $detail_url = $site ? admin_url('pitchsnap/detail/' . (int) $site->source_website_id) . '#tab-media' : admin_url('pitchsnap/websites');

        $category   = trim($this->input->post('category', true));
        $valid_cats = ['logo','team','project','equipment','award','certification','before_after','general'];
        if (!in_array($category, $valid_cats, true)) { $category = $media->category; }

        $alt_text = trim($this->input->post('alt_text', true)) ?: null;
        $this->pitchsnap_model->update_media($media_id, [
            'title'    => $alt_text ?: $media->title,
            'alt_text' => $alt_text,
            'category' => $category,
        ]);

        log_activity('ClickFuzz Web: Media metadata updated [Media ID: ' . $media_id . ']');
        set_alert('success', 'Media updated.');
        redirect($detail_url);
    }

    // POST pitchsnap/media_delete/{media_id}
    public function media_delete($media_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $media_id = (int) $media_id;
        $media    = $this->pitchsnap_model->get_media($media_id);
        if (!$media) { show_404(); }

        $site       = $this->pitchsnap_model->get_site_by_id($media->site_id);
        $detail_url = $site ? admin_url('pitchsnap/detail/' . (int) $site->source_website_id) . '#tab-media' : admin_url('pitchsnap/websites');

        // Check if media is attached to any pages
        $in_use = $this->db->where('media_id', $media_id)->count_all_results(db_prefix() . 'pitchsnap_page_media');
        if ($in_use > 0) {
            set_alert('danger', 'This media is attached to ' . $in_use . ' page(s). Detach it from all pages before deleting.');
            redirect($detail_url);
        }

        if (!function_exists('clickfuzz_web_delete_media_file')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_media_helper.php';
        }
        clickfuzz_web_delete_media_file($media->site_id, $media->filename);
        $this->pitchsnap_model->delete_media($media_id);

        log_activity('ClickFuzz Web: Media deleted [Media ID: ' . $media_id . ', Site: ' . $media->site_id . ']');
        set_alert('success', 'Media removed.');
        redirect($detail_url);
    }

    // GET pitchsnap/media_json/{site_id}  — for AJAX pickers
    public function media_json($site_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false]); }
        $site_id = (int) $site_id;
        $site    = $this->pitchsnap_model->get_site_by_id($site_id);
        if (!$site) { return $this->_json(['success' => false, 'media' => []]); }

        if (!function_exists('clickfuzz_web_media_url')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_media_helper.php';
        }

        $items = $this->pitchsnap_model->get_media_for_site($site_id);
        $out   = [];
        foreach ($items as $m) {
            $out[] = [
                'id'       => (int) $m->id,
                'title'    => $m->title,
                'alt_text' => $m->alt_text,
                'category' => $m->category,
                'url'      => clickfuzz_web_media_url($m->site_id, $m->filename),
            ];
        }
        return $this->_json(['success' => true, 'media' => $out]);
    }

    // -----------------------------------------------------------------------
    // Phase 3 — Page ↔ Media relationships
    // -----------------------------------------------------------------------

    // POST pitchsnap/page_media_attach/{page_id}
    public function page_media_attach($page_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }

        $page_id  = (int) $page_id;
        $media_id = (int) $this->input->post('media_id');
        $page     = $this->pitchsnap_model->get_page($page_id);
        $media    = $this->pitchsnap_model->get_media($media_id);

        if (!$page || !$media) {
            return $this->_json(['success' => false, 'message' => 'Page or media not found.']);
        }
        if ((int) $page->site_id !== (int) $media->site_id) {
            return $this->_json(['success' => false, 'message' => 'Cross-site media attachment is not allowed.']);
        }

        $ok = $this->pitchsnap_model->attach_media_to_page($page_id, $media_id);
        return $this->_json(['success' => $ok, 'message' => $ok ? 'Media attached.' : 'Attachment failed.']);
    }

    // POST pitchsnap/page_media_detach/{page_id}
    public function page_media_detach($page_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }

        $page_id  = (int) $page_id;
        $media_id = (int) $this->input->post('media_id');
        $page     = $this->pitchsnap_model->get_page($page_id);
        if (!$page) {
            return $this->_json(['success' => false, 'message' => 'Page not found.']);
        }

        $ok = $this->pitchsnap_model->detach_media_from_page($page_id, $media_id);
        return $this->_json(['success' => true, 'message' => 'Media detached.']);
    }

    // -----------------------------------------------------------------------
    // Phase 5 — Internal page publishing
    // -----------------------------------------------------------------------

    // POST pitchsnap/page_publish/{page_id}
    public function page_publish($page_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $page_id  = (int) $page_id;
        $page     = $this->pitchsnap_model->get_page($page_id);
        if (!$page) { show_404(); }

        $site     = $this->pitchsnap_model->get_site_by_id($page->site_id);
        $edit_url = admin_url('pitchsnap/page_edit/' . $page_id);

        $gen = $this->pitchsnap_model->get_current_page_generation($page_id);

        // Homepage pages may have no page generation record — fall back to the main site preview HTML.
        if ($page->page_type === 'homepage' && !$gen) {
            $fallback_html = $this->_resolve_site_preview_html($site);
            if (!$fallback_html) {
                set_alert('danger', 'No generated site HTML found. Generate the site first before publishing the homepage.');
                redirect($edit_url);
            }
            $gen = (object) [
                'id'                         => 0,
                'page_id'                    => $page->id,
                'site_id'                    => $page->site_id,
                'html_content'               => $fallback_html,
                'css_content'                => '',
                'js_content'                 => '',
                'meta_description_generated' => '',
            ];
        }

        // Eligibility
        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_publish_helper.php';
        $eligibility_error = clickfuzz_web_page_publish_eligible($page, $site, $gen);
        if ($eligibility_error) {
            set_alert('danger', $eligibility_error);
            redirect($edit_url);
        }

        $publish_type = $site->publish_type ?? 'html';

        // For WordPress: resolve parent WP page ID and parent menu-item ID before publishing
        $parent_wp_page_id        = null;
        $parent_primary_menu_item = 0;
        if ($publish_type === 'wordpress' && !empty($page->parent_page_id)) {
            $parent_page = $this->pitchsnap_model->get_page((int) $page->parent_page_id);
            if ($parent_page) {
                if ($parent_page->status !== 'published' || empty($parent_page->wp_page_id)) {
                    set_alert('danger', 'Parent page "' . e($parent_page->title) . '" must be published to WordPress first before this child page can be published.');
                    redirect($edit_url);
                }
                $parent_wp_page_id = (int) $parent_page->wp_page_id;
                // Use stored primary menu-item ID for deterministic sub-menu nesting.
                // This is the WP menu-item ID, NOT the WP page ID.
                $parent_primary_menu_item = (int) ($parent_page->wp_primary_menu_item_id ?? 0);
            }
        }

        // Dispatch by publish type
        if ($publish_type === 'wordpress') {
            $result = clickfuzz_web_publish_page_wp($page, $site, $gen, $parent_wp_page_id, $parent_primary_menu_item);
        } else {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
            $result = clickfuzz_web_publish_page_html($page, $site, $gen);
        }

        if (!$result['success']) {
            set_alert('danger', 'Publish failed: ' . $result['error']);
            redirect($edit_url);
        }

        // Mark page published in DB (store WP page ID and menu-item IDs where returned)
        $wp_page_id_to_store              = ($publish_type === 'wordpress') ? ($result['wp_page_id'] ?? null) : null;
        $wp_primary_menu_item_id_to_store = ($publish_type === 'wordpress') ? ($result['wp_primary_menu_item_id'] ?? null) : null;
        $wp_footer_menu_item_id_to_store  = ($publish_type === 'wordpress') ? ($result['wp_footer_menu_item_id'] ?? null) : null;
        $gen_id_published = ((int) $gen->id > 0) ? (int) $gen->id : null;
        $this->pitchsnap_model->publish_page(
            $page_id,
            $result['published_path'] ?? '',
            $wp_page_id_to_store,
            $wp_primary_menu_item_id_to_store,
            $wp_footer_menu_item_id_to_store,
            $gen_id_published
        );

        // Purge obsolete generation history — keep only the current generation (skip for synthetic homepage gen)
        if ((int) $gen->id > 0) {
            $this->pitchsnap_model->cleanup_page_generations($page_id, (int) $gen->id);
        }

        $live_url = $result['url'] ?? '';
        $url_html = $live_url ? ' <a href=\'' . e($live_url) . '\' target=\'_blank\'>' . e($live_url) . '</a>' : '';
        log_activity('ClickFuzz Web: Internal page published [Page #' . $page_id . ', URL: ' . $live_url . ']');
        set_alert('success', 'Page published successfully.' . $url_html);
        redirect($edit_url);
    }

    // POST pitchsnap/page_content_save/{page_id} — AJAX: save HTML as new page version
    public function page_content_save($page_id = '')
    {
        header('Content-Type: application/json');
        if (!is_admin()) { echo json_encode(['success' => false, 'error' => 'Forbidden']); return; }
        if ($this->input->method() !== 'post') { echo json_encode(['success' => false, 'error' => 'POST required']); return; }

        $page_id = (int) $page_id;
        $page    = $this->pitchsnap_model->get_page($page_id);
        if (!$page) { echo json_encode(['success' => false, 'error' => 'Page not found']); return; }

        $html = $this->input->post('html') ?? '';
        if (trim($html) === '') {
            echo json_encode(['success' => false, 'error' => 'HTML content cannot be empty']);
            return;
        }

        $gen_id = $this->pitchsnap_model->create_page_generation($page_id, $page->site_id, [
            'html_content' => $html,
            'css_content'  => '',
            'js_content'   => '',
            'source'       => 'manual_edit',
        ]);

        if (!$gen_id) {
            echo json_encode(['success' => false, 'error' => 'Failed to save version']);
            return;
        }

        $this->pitchsnap_model->set_current_page_generation($page_id, $gen_id);
        $this->pitchsnap_model->update_page($page_id, ['generation_status' => 'generated']);
        log_activity('ClickFuzz Web: Page HTML saved as new version [Page #' . $page_id . ']');

        echo json_encode(['success' => true, 'message' => 'New version saved.', 'gen_id' => $gen_id]);
    }

    // -----------------------------------------------------------------------
    // Phase 4 — Internal page AI generation
    // -----------------------------------------------------------------------

    // POST pitchsnap/page_generate/{page_id}
    // Queue a page for Anthropic generation via the cron runner.
    public function page_generate($page_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }

        $page_id  = (int) $page_id;
        $page     = $this->pitchsnap_model->get_page($page_id);
        if (!$page) { show_404(); }

        $site     = $this->pitchsnap_model->get_site_by_id($page->site_id);
        $edit_url = admin_url('pitchsnap/page_edit/' . $page_id);

        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_generation_helper.php';
        $queued = clickfuzz_web_queue_page_generation($page_id);

        if (!$queued['success']) {
            set_alert('danger', $queued['error']);
            redirect($edit_url);
        }

        log_activity('ClickFuzz Web: Page queued for generation [Page #' . $page_id . ']');
        set_alert('success', 'Page queued for generation. The cron runner will generate it shortly.');
        redirect($edit_url);
    }

    // GET pitchsnap/page_preview/{page_id}
    // Serve the generated page HTML for in-browser preview using the canonical
    // approved site header and footer. Supports ?gen= to preview specific versions.
    // Always noindex. No filesystem writes. Admin-only rendering.
    public function page_preview($page_id = '')
    {
        if (!is_staff_member()) { access_denied('ClickFuzz Web'); }

        $page_id = (int) $page_id;
        $page    = $this->pitchsnap_model->get_page($page_id);
        if (!$page) { show_404(); }

        $gen_id = (int) $this->input->get('gen');
        $gen    = $gen_id
            ? $this->pitchsnap_model->get_page_generation($gen_id)
            : $this->pitchsnap_model->get_current_page_generation($page_id);

        // Verify the requested generation belongs to this page
        if ($gen && (int) $gen->page_id !== $page_id) {
            $gen = null;
        }

        if (!$gen || empty($gen->html_content)) {
            show_error('No generated content available for this page yet.', 404);
        }

        $site = $this->pitchsnap_model->get_site_by_id($page->site_id);

        // Load rendering helpers — reuse Phase 5 publish functions, no duplication
        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_publish_helper.php';
        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_generation_helper.php';
        if (!function_exists('clickfuzz_web_normalize_copyright_year')) {
            require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_generation_helper.php';
        }

        // Extract canonical site chrome from the approved published homepage
        $canonical_header = '';
        $canonical_footer = '';
        $shared_head      = '';

        if ($site) {
            $homepage_html = '';

            // Primary: read from the published homepage on the sites server filesystem.
            // Only works when dashboard and sites server share the same filesystem.
            $domain    = $site->domain ?? '';
            $site_slug = ltrim(strstr($domain, '/sites/'), '/sites/');
            if ($site_slug && preg_match('/^[a-z0-9\-]+$/', $site_slug)) {
                $homepage_file = dirname(FCPATH) . '/sites/' . $site_slug . '/index.html';
                if (file_exists($homepage_file)) {
                    $homepage_html = (string) @file_get_contents($homepage_file);
                }
            }

            // Fallback: the source website's stored generation HTML (always accessible
            // on the dashboard server — used when sites are hosted on a separate server).
            if (empty($homepage_html) && !empty($site->source_website_id)) {
                $source_redesign = $this->pitchsnap_model->get((int) $site->source_website_id);
                if ($source_redesign && !empty($source_redesign->generation_result)) {
                    $homepage_html = $source_redesign->generation_result;
                }
            }

            if (!empty($homepage_html)) {
                $chrome           = clickfuzz_web_extract_site_chrome($homepage_html);
                $canonical_footer = $chrome['footer'];

                // head_inner has <style> blocks stripped (publish flow uses assets/style.css).
                // For preview we have no live URL for that file, so embed the site CSS inline.
                $site_css_chunks = [];
                if (preg_match_all('/<style[^>]*>([\s\S]*?)<\/style>/i', $homepage_html, $cm)) {
                    foreach ($cm[1] as $chunk) {
                        $t = trim($chunk);
                        if ($t !== '') { $site_css_chunks[] = $t; }
                    }
                }
                $shared_head = $chrome['head_inner'];
                if ($site_css_chunks) {
                    $shared_head .= "\n<style>" . implode("\n", $site_css_chunks) . '</style>';
                }

                // Build nav from current published page registry
                $domain_row    = $this->pitchsnap_model->get_platform_domain_for_site($site->id);
                $site_base_url = $domain_row ? 'https://' . $domain_row->hostname : null;

                if ($site_base_url && !empty($chrome['header'])) {
                    $all_pages     = $this->pitchsnap_model->get_pages_for_site($site->id, true);
                    $pages_indexed = [];
                    foreach ($all_pages as $p) { $pages_indexed[(int) $p->id] = $p; }

                    $nav_data = clickfuzz_web_build_nav_items($pages_indexed, $site_base_url);
                    $nav_html = clickfuzz_web_render_primary_nav_html($nav_data['primary'], $site_base_url . '/');

                    $canonical_header = clickfuzz_web_update_html_nav($chrome['header'], $nav_html);
                } else {
                    $canonical_header = $chrome['header'] ?? '';
                }
            }
        }

        // Force noindex on preview — never influence search indexing
        $preview_page             = clone $page;
        $preview_page->noindex_page = 1;

        // No canonical URL on preview (page may not yet be published)
        $html = clickfuzz_web_render_full_page_html(
            $preview_page, $site, $gen,
            '', // no canonical URL
            $canonical_header,
            $canonical_footer,
            $shared_head
        );

        // Output directly — no filesystem writes, no status changes, no cleanup
        $this->output
            ->set_content_type('text/html')
            ->set_output($html);
    }

    // POST pitchsnap/page_generation_set_current/{generation_id}
    // Make a specific version the current generation for its page.
    public function page_generation_set_current($generation_id = '')
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        if ($this->input->method() !== 'post') { redirect(admin_url('pitchsnap/websites')); }

        $generation_id = (int) $generation_id;
        $gen           = $this->pitchsnap_model->get_page_generation($generation_id);
        if (!$gen) { show_404(); }

        $page     = $this->pitchsnap_model->get_page($gen->page_id);
        $edit_url = admin_url('pitchsnap/page_edit/' . $gen->page_id);

        $ok = $this->pitchsnap_model->set_current_page_generation($gen->page_id, $generation_id);
        if ($ok) {
            log_activity('ClickFuzz Web: Page generation version set [Page #' . $gen->page_id . ', Gen #' . $generation_id . ']');

            // Auto-push to WordPress when already published
            $site         = $this->pitchsnap_model->get_site_by_id($page->site_id);
            $publish_type = $site ? ($site->publish_type ?? 'html') : 'html';
            if ($publish_type === 'wordpress' && !empty($page->wp_page_id) && $site) {
                $new_gen = $this->pitchsnap_model->get_page_generation($generation_id);
                if ($new_gen) {
                    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_publish_helper.php';
                    $parent_wp_page_id = null;
                    if (!empty($page->parent_page_id)) {
                        $parent_page = $this->pitchsnap_model->get_page((int) $page->parent_page_id);
                        if ($parent_page && !empty($parent_page->wp_page_id)) {
                            $parent_wp_page_id = (int) $parent_page->wp_page_id;
                        }
                    }
                    $push = clickfuzz_web_publish_page_wp($page, $site, $new_gen, $parent_wp_page_id, 0);
                    if ($push['success']) {
                        $this->pitchsnap_model->publish_page($page->id, $page->published_path ?? '', $push['wp_page_id'] ?? null, null, null);
                        set_alert('success', 'Version set and pushed to WordPress.');
                    } else {
                        set_alert('warning', 'Version set. WordPress push failed: ' . $push['error']);
                    }
                } else {
                    set_alert('success', 'Version set as current.');
                }
            } else {
                set_alert('success', 'Version set as current.');
            }
        } else {
            set_alert('danger', 'Could not set version — it may not belong to this page.');
        }
        redirect($edit_url);
    }

    // ── Onboarding Flows ──────────────────────────────────────────────────────

    public function flows()
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $data['title'] = 'Onboarding Flows';
        $data['flows'] = $this->pitchsnap_model->get_all_flows();
        $this->load->view('pitchsnap/admin_flows', $data);
    }

    public function flow_save()
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        $id   = (int) $this->input->post('id');
        $name = trim($this->input->post('name'));
        if ($name === '') { $this->_json(['success' => false, 'message' => 'Name is required']); return; }

        $payload = [
            'name'        => $name,
            'description' => trim($this->input->post('description')),
            'status'      => in_array($this->input->post('status'), ['active', 'inactive'])
                                ? $this->input->post('status') : 'active',
        ];

        if ($id) {
            $this->pitchsnap_model->update_flow($id, $payload);
            $this->_json(['success' => true, 'message' => 'Flow updated']);
        } else {
            $new_id = $this->pitchsnap_model->create_flow($payload);
            $this->_json(['success' => true, 'id' => $new_id]);
        }
    }

    public function flow_toggle($id)
    {
        if (!is_admin()) { $this->_json(['success' => false]); return; }
        $flow = $this->pitchsnap_model->get_flow((int) $id);
        if (!$flow) { $this->_json(['success' => false, 'message' => 'Not found']); return; }
        $new_status = $flow['status'] === 'active' ? 'inactive' : 'active';
        $this->pitchsnap_model->update_flow((int) $id, ['status' => $new_status]);
        $this->_json(['success' => true, 'status' => $new_status]);
    }

    public function flow_duplicate($id)
    {
        if (!is_admin()) { $this->_json(['success' => false]); return; }
        $flow = $this->pitchsnap_model->get_flow((int) $id);
        if (!$flow) { $this->_json(['success' => false, 'message' => 'Not found']); return; }
        unset($flow['id'], $flow['created_at'], $flow['updated_at']);
        $flow['name']   = $flow['name'] . ' (Copy)';
        $flow['status'] = 'inactive';
        $new_id = $this->pitchsnap_model->create_flow($flow);
        $this->_json(['success' => true, 'id' => $new_id]);
    }

    public function flow_delete($id)
    {
        if (!is_admin()) { $this->_json(['success' => false]); return; }
        $ok = $this->pitchsnap_model->delete_flow((int) $id);
        $this->_json(['success' => $ok]);
    }

    public function flow_sections($flow_id = null)
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $flow = $this->pitchsnap_model->get_flow((int) $flow_id);
        if (!$flow) { show_404(); return; }
        $data['title']    = 'Sections — ' . $flow['name'];
        $data['flow']     = $flow;
        $data['sections'] = $this->pitchsnap_model->get_sections_for_flow((int) $flow_id);
        $this->load->view('pitchsnap/admin_flow_sections', $data);
    }

    public function flow_save_page_url($flow_id = null)
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }
        $flow_id = (int) $flow_id;
        if (!$flow_id) { $this->_json(['success' => false, 'message' => 'Invalid flow']); return; }
        $flow = $this->pitchsnap_model->get_flow($flow_id);
        if (!$flow) { $this->_json(['success' => false, 'message' => 'Flow not found']); return; }
        $page_url = trim($this->input->post('page_url') ?? '');
        if ($page_url !== '' && !filter_var($page_url, FILTER_VALIDATE_URL)) {
            $this->_json(['success' => false, 'message' => 'Enter a valid URL (include https://)']); return;
        }
        $this->pitchsnap_model->update_flow($flow_id, ['page_url' => $page_url ?: null]);
        $this->_json(['success' => true, 'page_url' => $page_url]);
    }

    public function section_save()
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }

        $id      = (int) $this->input->post('id');
        $flow_id = (int) $this->input->post('flow_id');
        $name    = trim($this->input->post('name'));

        if (!$flow_id) { $this->_json(['success' => false, 'message' => 'Invalid flow']); return; }
        if ($name === '') { $this->_json(['success' => false, 'message' => 'Name is required']); return; }

        $payload = [
            'name'        => $name,
            'description' => trim($this->input->post('description')),
        ];

        if ($id) {
            $this->pitchsnap_model->update_section($id, $payload);
            $this->_json(['success' => true, 'message' => 'Section updated']);
        } else {
            $payload['flow_id'] = $flow_id;
            $new_id = $this->pitchsnap_model->create_section($payload);
            $this->_json(['success' => true, 'id' => $new_id]);
        }
    }

    public function section_delete($id)
    {
        if (!is_admin()) { $this->_json(['success' => false]); return; }
        $ok = $this->pitchsnap_model->delete_section((int) $id);
        $this->_json(['success' => $ok]);
    }

    public function section_questions($section_id = null)
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $section = $this->pitchsnap_model->get_section((int) $section_id);
        if (!$section) { show_404(); return; }
        $flow      = $this->pitchsnap_model->get_flow((int) $section['flow_id']);
        $questions = $this->pitchsnap_model->get_questions_for_section((int) $section_id);

        $q_ids   = array_column($questions, 'id');
        $tag_map = $this->pitchsnap_model->get_tags_for_questions($q_ids);
        foreach ($questions as &$q) {
            $q['usage_tags'] = $tag_map[(int) $q['id']] ?? [];
        }
        unset($q);

        $data['title']          = 'Questions — ' . $section['name'];
        $data['section']        = $section;
        $data['flow']           = $flow;
        $data['questions']      = $questions;
        $data['flow_questions'] = $this->pitchsnap_model->get_questions_in_flow_sequence((int) $section['flow_id']);
        $data['usage_tags']     = $this->pitchsnap_model->get_all_usage_tags();
        $this->load->view('pitchsnap/admin_section_questions', $data);
    }

    public function question_save()
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }

        $id         = (int) $this->input->post('id');
        $section_id = (int) $this->input->post('section_id');
        $label      = trim($this->input->post('label'));
        $field_type = $this->input->post('field_type');

        $valid_types = ['text','textarea','number','email','phone','url','select','radio','checkbox','yes_no','question_builder','file','phone_number_picker'];
        if (!$section_id) { $this->_json(['success' => false, 'message' => 'Invalid section']); return; }
        if ($label === '') { $this->_json(['success' => false, 'message' => 'Label is required']); return; }
        if (!in_array($field_type, $valid_types, true)) { $this->_json(['success' => false, 'message' => 'Invalid field type']); return; }

        $data_key = strtolower(trim($this->input->post('data_key')));
        $purpose  = $this->input->post('purpose');

        if ($data_key === '') { $this->_json(['success' => false, 'message' => 'Data Key is required']); return; }
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $data_key)) {
            $this->_json(['success' => false, 'message' => 'Data Key must be dot-separated lowercase segments (e.g. business.name)']);
            return;
        }
        if (!in_array($purpose, ['data', 'quote_form_definition'], true)) {
            $this->_json(['success' => false, 'message' => 'Invalid purpose']);
            return;
        }

        $section = $this->pitchsnap_model->get_section($section_id);
        if (!$section) { $this->_json(['success' => false, 'message' => 'Invalid section']); return; }
        if ($this->pitchsnap_model->question_data_key_exists_in_flow($data_key, (int) $section['flow_id'], $id ?: null)) {
            $this->_json(['success' => false, 'message' => 'Data Key "' . $data_key . '" is already used in this flow']);
            return;
        }

        // Condition validation
        $condition_question_id = (int) $this->input->post('condition_question_id');
        $condition_operator    = $this->input->post('condition_operator');
        $condition_value       = trim($this->input->post('condition_value'));

        if ($condition_question_id) {
            if (!in_array($condition_operator, ['equals', 'not_equals', 'contains'], true)) {
                $this->_json(['success' => false, 'message' => 'Invalid condition operator']); return;
            }
            if ($condition_value === '') {
                $this->_json(['success' => false, 'message' => 'Condition value is required']); return;
            }
            if ($id && $condition_question_id === $id) {
                $this->_json(['success' => false, 'message' => 'A question cannot depend on itself']); return;
            }
            $ctrl_q = $this->pitchsnap_model->get_question($condition_question_id);
            if (!$ctrl_q) {
                $this->_json(['success' => false, 'message' => 'Controlling question not found']); return;
            }
            $ctrl_sec = $this->pitchsnap_model->get_section((int) $ctrl_q['section_id']);
            if (!$ctrl_sec || (int) $ctrl_sec['flow_id'] !== (int) $section['flow_id']) {
                $this->_json(['success' => false, 'message' => 'Controlling question must belong to the same flow']); return;
            }
            // Sequence check: controlling must appear before the dependent question
            $seq      = $this->pitchsnap_model->get_questions_in_flow_sequence((int) $section['flow_id']);
            $ctrl_pos = -1;
            $dep_pos  = -1;
            foreach ($seq as $i => $sq) {
                if ((int) $sq['id'] === $condition_question_id) { $ctrl_pos = $i; }
                if ($id && (int) $sq['id'] === $id)             { $dep_pos  = $i; }
            }
            if ($ctrl_pos === -1) {
                $this->_json(['success' => false, 'message' => 'Controlling question not found in flow']); return;
            }
            if ($id && $dep_pos !== -1 && $ctrl_pos >= $dep_pos) {
                $this->_json(['success' => false, 'message' => 'Controlling question must appear earlier in the flow']); return;
            }
        }

        $options_json = null;
        if (in_array($field_type, ['select','radio','checkbox'], true)) {
            $raw     = $this->input->post('options_json');
            $decoded = json_decode($raw, true);
            $options_json = (is_array($decoded) && !empty($decoded)) ? json_encode(array_values($decoded)) : null;
        }

        $extraction_map_json = null;
        if ($field_type === 'file') {
            $raw_map = $this->input->post('extraction_map_json');
            if ($raw_map && $raw_map !== 'null') {
                $map = json_decode($raw_map, true);
                if (is_array($map) && !empty($map)) {
                    $allowed_fields = ['business_name','ein','street_address','city','state','postal_code'];
                    $flow_keys = [];
                    foreach ($this->pitchsnap_model->get_questions_in_flow_sequence((int) $section['flow_id']) as $fq) {
                        if (!empty($fq['data_key'])) { $flow_keys[] = $fq['data_key']; }
                    }
                    $valid_rows = [];
                    foreach ($map as $row) {
                        $ef = isset($row['extraction_field']) ? $row['extraction_field'] : '';
                        $dk = isset($row['data_key'])         ? trim($row['data_key'])   : '';
                        if (!in_array($ef, $allowed_fields, true)) { continue; }
                        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $dk)) { continue; }
                        if (!in_array($dk, $flow_keys, true)) {
                            $this->_json(['success' => false, 'message' => 'Extraction target "' . $dk . '" is not a question in this flow.']);
                            return;
                        }
                        $valid_rows[] = ['extraction_field' => $ef, 'data_key' => $dk];
                    }
                    $extraction_map_json = !empty($valid_rows) ? json_encode($valid_rows) : null;
                }
            }
        }

        $payload = [
            'label'                => $label,
            'data_key'             => $data_key,
            'purpose'              => $purpose,
            'help_text'            => trim($this->input->post('help_text')),
            'field_type'           => $field_type,
            'required'             => $this->input->post('required') === '1' ? 1 : 0,
            'options_json'         => $options_json,
            'extraction_map_json'  => $extraction_map_json,
            'condition_question_id'=> $condition_question_id ?: null,
            'condition_operator'   => $condition_question_id ? $condition_operator : null,
            'condition_value'      => $condition_question_id ? $condition_value    : null,
        ];

        $raw_tag_ids = $this->input->post('tag_ids');
        $tag_ids = [];
        if (is_array($raw_tag_ids)) {
            foreach ($raw_tag_ids as $tid) {
                $tid = (int) $tid;
                if ($tid > 0) { $tag_ids[] = $tid; }
            }
        }

        if ($id) {
            $this->pitchsnap_model->update_question($id, $payload);
            $this->pitchsnap_model->sync_question_tags($id, $tag_ids);
            $this->_json(['success' => true, 'message' => 'Question updated']);
        } else {
            $payload['section_id'] = $section_id;
            $new_id = $this->pitchsnap_model->create_question($payload);
            if ($new_id) {
                $this->pitchsnap_model->sync_question_tags($new_id, $tag_ids);
            }
            $this->_json(['success' => true, 'id' => $new_id]);
        }
    }

    public function question_delete($id)
    {
        if (!is_admin()) { $this->_json(['success' => false]); return; }
        $ok = $this->pitchsnap_model->delete_question((int) $id);
        $this->_json(['success' => $ok]);
    }

    public function question_reorder()
    {
        if (!is_admin()) { $this->_json(['success' => false]); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }
        $section_id  = (int) $this->input->post('section_id');
        $ordered_ids = $this->input->post('ids');
        if (!$section_id || !is_array($ordered_ids)) { $this->_json(['success' => false]); return; }

        // Guard: reject if the new order would place any conditional question before its controller
        $section = $this->pitchsnap_model->get_section($section_id);
        if ($section) {
            $seq         = $this->pitchsnap_model->get_questions_in_flow_sequence((int) $section['flow_id']);
            $new_sec_ids = array_map('intval', $ordered_ids);

            // Build simulated full sequence: replace this section's block with the new order
            $simulated    = [];
            $sec_inserted = false;
            foreach ($seq as $q) {
                if ((int) $q['section_id'] === $section_id) {
                    if (!$sec_inserted) {
                        foreach ($new_sec_ids as $nid) { $simulated[] = $nid; }
                        $sec_inserted = true;
                    }
                } else {
                    $simulated[] = (int) $q['id'];
                }
            }
            if (!$sec_inserted) {
                foreach ($new_sec_ids as $nid) { $simulated[] = $nid; }
            }

            $pos_map = array_flip($simulated);
            foreach ($seq as $q) {
                if (empty($q['condition_question_id'])) { continue; }
                $dep_id  = (int) $q['id'];
                $ctrl_id = (int) $q['condition_question_id'];
                if (!isset($pos_map[$dep_id], $pos_map[$ctrl_id])) { continue; }
                if ($pos_map[$ctrl_id] >= $pos_map[$dep_id]) {
                    $this->_json(['success' => false, 'message' => 'Reorder rejected: a conditional question would appear before its controlling question.']);
                    return;
                }
            }
        }

        $this->pitchsnap_model->reorder_questions($section_id, $ordered_ids);
        $this->_json(['success' => true]);
    }

    public function section_move($id, $direction = 'up')
    {
        if (!is_admin()) { $this->_json(['success' => false]); return; }
        $ok = $this->pitchsnap_model->move_section((int) $id, $direction === 'down' ? 'down' : 'up');
        $this->_json(['success' => $ok]);
    }

    public function usage_tags()
    {
        if (!is_admin()) { access_denied('ClickFuzz Web'); }
        $data['title']      = 'Onboarding — Usage Tags';
        $data['usage_tags'] = $this->pitchsnap_model->get_all_usage_tags();
        $this->load->view('pitchsnap/admin_usage_tags', $data);
    }

    public function usage_tag_save()
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }

        $id   = (int) $this->input->post('id');
        $name = trim($this->input->post('name'));
        $slug = trim(strtolower($this->input->post('slug')));
        $desc = trim($this->input->post('description'));

        if ($name === '') { $this->_json(['success' => false, 'message' => 'Name is required']); return; }
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            $this->_json(['success' => false, 'message' => 'Slug must be lowercase letters, numbers, underscores (start with a letter)']);
            return;
        }

        $existing = $this->pitchsnap_model->get_usage_tag_by_slug($slug);
        if ($existing && (int) $existing['id'] !== $id) {
            $this->_json(['success' => false, 'message' => 'Slug "' . $slug . '" is already in use']);
            return;
        }

        $payload = ['name' => $name, 'slug' => $slug, 'description' => $desc ?: null];
        if ($id) {
            $this->pitchsnap_model->update_usage_tag($id, $payload);
            $this->_json(['success' => true, 'message' => 'Tag updated']);
        } else {
            $new_id = $this->pitchsnap_model->create_usage_tag($payload);
            $this->_json(['success' => true, 'id' => $new_id]);
        }
    }

    public function usage_tag_delete($tag_id = null)
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }
        $tag_id = (int) $tag_id;
        if (!$tag_id) { $this->_json(['success' => false, 'message' => 'Invalid tag']); return; }
        if ($this->pitchsnap_model->tag_in_use($tag_id)) {
            $this->_json(['success' => false, 'message' => 'Cannot delete: this tag is assigned to one or more questions']);
            return;
        }
        $this->pitchsnap_model->delete_usage_tag($tag_id);
        $this->_json(['success' => true]);
    }

    public function onboarding_link_create()
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }

        $site_id = (int) $this->input->post('site_id');
        $flow_id = (int) $this->input->post('flow_id');
        if (!$site_id) { $this->_json(['success' => false, 'message' => 'Invalid site']); return; }
        if (!$flow_id) { $this->_json(['success' => false, 'message' => 'Select a flow']); return; }

        $site = $this->pitchsnap_model->get_site_by_id($site_id);
        if (!$site) { $this->_json(['success' => false, 'message' => 'Site not found']); return; }
        $flow = $this->pitchsnap_model->get_flow($flow_id);
        if (!$flow) { $this->_json(['success' => false, 'message' => 'Flow not found']); return; }

        $token = $this->pitchsnap_model->create_onboarding_link($site_id, $flow_id);
        if (!$token) { $this->_json(['success' => false, 'message' => 'Could not create link']); return; }

        $ob_page = $flow['page_url'] ?: get_option('pitchsnap_onboarding_page_url');
        $ob_url  = $ob_page
            ? rtrim($ob_page, '/') . '/?token=' . $token
            : base_url('pitchsnap/onboarding_embed') . '?token=' . $token;
        $this->_json(['success' => true, 'url' => $ob_url]);
    }

    public function onboarding_link_revoke($id = null)
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }
        $id = (int) $id;
        if (!$id) { $this->_json(['success' => false, 'message' => 'Invalid link']); return; }
        $this->pitchsnap_model->revoke_onboarding_link($id);
        $this->_json(['success' => true]);
    }

    public function site_data_save()
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }

        $site_id  = (int) $this->input->post('site_id');
        $data_key = trim(strtolower($this->input->post('data_key')));
        $value    = $this->input->post('value');

        if (!$site_id) { $this->_json(['success' => false, 'message' => 'Invalid site']); return; }
        if ($data_key === '') { $this->_json(['success' => false, 'message' => 'Data Key is required']); return; }
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $data_key)) {
            $this->_json(['success' => false, 'message' => 'Data Key must be dot-separated lowercase segments (e.g. business.name)']);
            return;
        }
        if ($value === '' || $value === null) { $this->_json(['success' => false, 'message' => 'Value is required']); return; }

        $site = $this->pitchsnap_model->get_site_by_id($site_id);
        if (!$site) { $this->_json(['success' => false, 'message' => 'Site not found']); return; }

        $this->pitchsnap_model->upsert_site_data($site_id, $data_key, $value);
        $this->_json(['success' => true]);
    }

    public function site_data_delete($id = null)
    {
        if (!is_admin()) { $this->_json(['success' => false, 'message' => 'Access denied']); return; }
        if ($this->input->method() !== 'post') { $this->_json(['success' => false]); return; }
        $id = (int) $id;
        if (!$id) { $this->_json(['success' => false, 'message' => 'Invalid record']); return; }
        $this->pitchsnap_model->delete_site_data_by_id($id);
        $this->_json(['success' => true]);
    }

    // -----------------------------------------------------------------------
    // Forms
    // -----------------------------------------------------------------------

    // GET pitchsnap/ghl_destinations_json/{site_id}
    // Returns ClickFuzz GHL destination definitions (global + site-specific).
    public function ghl_destinations_json($site_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false]); }
        $site_id = (int) $site_id;
        $dests   = $this->pitchsnap_model->get_ghl_destinations($site_id ?: null);
        $list    = [];
        foreach ($dests as $d) {
            $list[] = [
                'id'      => (int) $d->id,
                'label'   => $d->label,
                'ghl_key' => $d->ghl_key,
                'mode'    => $d->mode,
                'global'  => ($d->site_id === null),
            ];
        }
        return $this->_json(['success' => true, 'destinations' => $list]);
    }

    // POST pitchsnap/ghl_dest_save — create or update a GHL destination
    public function ghl_dest_save()
    {
        if (!is_admin()) { return $this->_json(['success' => false]); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false]); }
        $id          = (int) $this->input->post('id');
        $label       = trim($this->input->post('label', true));
        $ghl_key     = trim($this->input->post('ghl_key', true));
        $mode        = $this->input->post('mode') === 'multiple' ? 'multiple' : 'single';
        $site_id_raw = $this->input->post('site_id');
        if ($label === '') { return $this->_json(['success' => false, 'message' => 'Label is required.']); }
        $data = ['label' => $label, 'ghl_key' => $ghl_key, 'mode' => $mode, 'active' => 1];
        if ($id) {
            $this->pitchsnap_model->update_ghl_destination($id, $data);
        } else {
            if ($site_id_raw !== '' && $site_id_raw !== false && $site_id_raw !== null) {
                $data['site_id'] = (int) $site_id_raw;
            }
            $id = $this->pitchsnap_model->create_ghl_destination($data);
        }
        $dest = $this->pitchsnap_model->get_ghl_destination($id);
        return $this->_json(['success' => true, 'destination' => $dest, 'csrf_hash' => $this->security->get_csrf_hash()]);
    }

    // POST pitchsnap/ghl_dest_delete/{id}
    public function ghl_dest_delete($id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false]); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false]); }
        $id = (int) $id;
        if (!$id) { return $this->_json(['success' => false]); }
        $this->pitchsnap_model->delete_ghl_destination($id);
        return $this->_json(['success' => true, 'csrf_hash' => $this->security->get_csrf_hash()]);
    }

    // POST pitchsnap/form_save/{site_id}  (create or update)
    public function form_save($site_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }

        $site_id = (int) $site_id;
        $site    = $this->pitchsnap_model->get_site_by_id($site_id);
        if (!$site) { return $this->_json(['success' => false, 'message' => 'Site not found.']); }

        $form_id   = (int) $this->input->post('form_id');
        $name      = trim($this->input->post('name', true));
        $fields_raw = $this->input->post('fields');
        $settings_raw = $this->input->post('settings');

        if ($name === '') { return $this->_json(['success' => false, 'message' => 'Form name is required.']); }

        $fields   = (is_array($fields_raw)) ? json_encode($fields_raw) : ($fields_raw ?: '[]');
        $settings = (is_array($settings_raw)) ? json_encode($settings_raw) : ($settings_raw ?: '{}');

        // Server-side duplicate check: Single Input destinations may only appear once per form.
        $decoded_fields = json_decode($fields, true) ?: [];
        $single_seen    = [];
        foreach ($decoded_fields as $ff) {
            $ghl_key   = trim((string) ($ff['ghl_field']    ?? ''));
            $dest_mode = $ff['ghl_dest_mode'] ?? 'single';
            if ($ghl_key === '' || $dest_mode === 'multiple') { continue; }
            if (isset($single_seen[$ghl_key])) {
                return $this->_json(['success' => false, 'message' => 'Duplicate GHL destination: "' . htmlspecialchars($ghl_key) . '" is Single Input — only one field per form may use it.']);
            }
            $single_seen[$ghl_key] = true;
        }

        if ($form_id) {
            $form = $this->pitchsnap_model->get_form($form_id);
            if (!$form || (int) $form->site_id !== $site_id) {
                return $this->_json(['success' => false, 'message' => 'Form not found.']);
            }
            $this->pitchsnap_model->update_form($form_id, ['name' => $name, 'fields' => $fields, 'settings' => $settings]);
            return $this->_json(['success' => true, 'message' => 'Form saved.', 'form_id' => $form_id]);
        }

        $new_id = $this->pitchsnap_model->create_form([
            'site_id'   => $site_id,
            'name'      => $name,
            'form_type' => 'custom',
            'fields'    => $fields,
            'settings'  => $settings,
        ]);
        return $this->_json(['success' => true, 'message' => 'Form created.', 'form_id' => $new_id]);
    }

    // POST pitchsnap/form_delete/{form_id}
    public function form_delete($form_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }

        $form_id = (int) $form_id;
        $form    = $this->pitchsnap_model->get_form($form_id);
        if (!$form) { return $this->_json(['success' => false, 'message' => 'Form not found.']); }
        if ($form->form_type === 'system') { return $this->_json(['success' => false, 'message' => 'System forms cannot be deleted.']); }

        $this->pitchsnap_model->delete_form($form_id);
        return $this->_json(['success' => true, 'message' => 'Form deleted.']);
    }

    // POST pitchsnap/form_placement_add/{form_id}
    public function form_placement_add($form_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }

        $form_id  = (int) $form_id;
        $page_id  = (int) $this->input->post('page_id');
        $placement = in_array($this->input->post('placement'), ['inline','popup'], true)
            ? $this->input->post('placement') : 'inline';

        if (!$form_id || !$page_id) { return $this->_json(['success' => false, 'message' => 'form_id and page_id required.']); }

        $id = $this->pitchsnap_model->add_placement(['form_id' => $form_id, 'page_id' => $page_id, 'placement' => $placement]);
        return $this->_json(['success' => true, 'placement_id' => $id]);
    }

    // GET pitchsnap/form_placements_json/{form_id}
    public function form_placements_json($form_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false]); }
        $placements = $this->pitchsnap_model->get_placements_for_form((int) $form_id);
        return $this->_json(['success' => true, 'placements' => $placements]);
    }

    // POST pitchsnap/form_placement_remove/{placement_id}
    public function form_placement_remove($placement_id = '')
    {
        if (!is_admin()) { return $this->_json(['success' => false, 'message' => 'Access denied.']); }
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false, 'message' => 'Invalid request.']); }

        $this->pitchsnap_model->remove_placement((int) $placement_id);
        return $this->_json(['success' => true]);
    }

    // POST pitchsnap/save_ghl_tracking_id/{site_id}
    public function save_ghl_tracking_id($site_id = '')
    {
        is_admin() || show_404();
        if ($this->input->method() !== 'post') { return $this->_json(['success' => false], 405); }
        $site_id     = (int) $site_id;
        if (!$site_id) { return $this->_json(['success' => false, 'message' => 'Invalid site ID.'], 400); }
        $tracking_id = trim((string) $this->input->post('tracking_id'));
        if ($tracking_id !== '' && !preg_match('/^tk_[a-zA-Z0-9_-]+$/', $tracking_id)) {
            return $this->_json(['success' => false, 'message' => 'Invalid tracking ID — must start with tk_.'], 422);
        }
        update_option('pitchsnap_ghl_tracking_id_' . $site_id, $tracking_id);
        return $this->_json(['success' => true]);
    }

    private function _json($data)
    {
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }

    public function onboarding_doc_download($site_id, $filename)
    {
        if (!is_admin()) { show_404(); return; }
        $site_id  = (int) $site_id;
        $filename = basename((string) $filename);
        if (!$site_id || !$filename) { show_404(); return; }
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $filename)) { show_404(); return; }
        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_media_helper.php';
        if (!clickfuzz_web_stream_ob_doc($site_id, $filename)) {
            show_404();
        }
    }

    /**
     * Resolves the primary site preview HTML from disk for a given site record.
     * Uses the same resolution logic as clickfuzz_web_publish_site.
     */
    private function _resolve_site_preview_html($site)
    {
        $source_website = $this->pitchsnap_model->get((int) ($site->source_website_id ?? 0));
        if (!$source_website) { return null; }

        $lead_id = $source_website->lead_id ?? null;
        if ($lead_id) {
            $primary = $this->pitchsnap_model->get_primary_for_lead((int) $lead_id);
            $website = ($primary && !empty($primary->preview_token)) ? $primary : $source_website;
        } else {
            $website = $source_website;
        }

        if (empty($website->preview_token)) { return null; }

        $preview_file = dirname(FCPATH) . '/previews/' . $website->preview_token . '/index.html';
        $html = @file_get_contents($preview_file);
        return $html ?: null;
    }
}
