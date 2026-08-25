<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Pitchsnap_model extends App_Model
{
    private $table;
    private $site_table;
    private $agreement_table;
    private $log_table;
    private $domain_table;

    public function __construct()
    {
        parent::__construct();
        $this->table            = db_prefix() . 'pitchsnap_redesigns';
        $this->site_table       = db_prefix() . 'pitchsnap_sites';
        $this->agreement_table  = db_prefix() . 'pitchsnap_agreements';
        $this->log_table        = db_prefix() . 'pitchsnap_logs';
        $this->domain_table     = db_prefix() . 'pitchsnap_site_domains';
    }

    // -----------------------------------------------------------------------
    // Basic CRUD
    // -----------------------------------------------------------------------

    public function get($id)
    {
        return $this->db->get_where($this->table, ['id' => (int) $id])->row();
    }

    public function get_all()
    {
        $prefix = db_prefix();
        $this->db->select(
            $this->table . '.*, ' .
            $prefix . 'leads.company as lead_company, ' .
            $prefix . 'leads.name  as lead_name, ' .
            $prefix . 'leads.email as lead_email'
        );
        $this->db->from($this->table);
        $this->db->join($prefix . 'leads', $prefix . 'leads.id = ' . $this->table . '.lead_id', 'left');
        $this->db->order_by($this->table . '.dateadded', 'DESC');

        return $this->db->get()->result_array();
    }

    public function get_latest_per_lead()
    {
        $prefix = db_prefix();
        $sub    = 'SELECT lead_id, MAX(id) as max_id FROM ' . $this->table . ' GROUP BY lead_id';
        $this->db->select(
            $this->table . '.*, ' .
            $prefix . 'leads.company as lead_company, ' .
            $prefix . 'leads.name  as lead_name, ' .
            $prefix . 'leads.email as lead_email'
        );
        $this->db->from($this->table);
        $this->db->join(
            '(' . $sub . ') _ps_latest',
            '_ps_latest.lead_id = ' . $this->table . '.lead_id AND _ps_latest.max_id = ' . $this->table . '.id',
            'inner'
        );
        $this->db->join($prefix . 'leads', $prefix . 'leads.id = ' . $this->table . '.lead_id', 'left');
        $this->db->order_by($this->table . '.dateadded', 'DESC');

        return $this->db->get()->result_array();
    }

    public function get_by_lead($lead_id)
    {
        $this->db->where('lead_id', (int) $lead_id);
        $this->db->order_by('dateadded', 'DESC');

        return $this->db->get($this->table)->result_array();
    }

    public function create($data)
    {
        $data['preview_token'] = $this->generate_token();
        $data['status']        = $data['status']   ?? 'new';
        $data['vertical']      = $data['vertical'] ?? 'Local Business';
        $data['dateadded']     = date('Y-m-d H:i:s');

        $this->db->set('dateupdated', 'NOW()', false);
        $this->db->insert($this->table, $data);
        $insert_id = $this->db->insert_id();

        if ($insert_id) {
            log_activity('PitchSnap: Website record created [Website ID: ' . $insert_id . ', Lead ID: ' . ($data['lead_id'] ?? '?') . ']');
            return $insert_id;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Generation lifecycle — Phase 1: Start new generation
    // -----------------------------------------------------------------------

    public function get_pending_generation($limit = 5)
    {
        return $this->db
            ->where('status', 'pending_generation')
            ->order_by('dateadded', 'ASC')
            ->limit((int) $limit)
            ->get($this->table)
            ->result();
    }

    public function claim_for_generation($id)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->where('status', 'pending_generation')
            ->update($this->table, ['status' => 'generating']);

        return $this->db->affected_rows() > 0;
    }

    public function save_generation_prompt($id, $rendered_prompt)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, ['generation_prompt' => $rendered_prompt]);
    }

    public function save_manus_task_started($id, $task_id, $provider_name = 'manus')
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, [
                'provider'            => $provider_name,
                'provider_project_id' => $task_id,
                'status'              => 'generating',
            ]);
    }

    // -----------------------------------------------------------------------
    // Generation lifecycle — Phase 2: Poll Manus task, then publish
    // -----------------------------------------------------------------------

    public function get_generating_manus($limit = 5)
    {
        return $this->db
            ->where('status', 'generating')
            ->where('provider', 'manus')
            ->where('provider_project_id IS NOT NULL', null, false)
            ->order_by('dateupdated', 'ASC')
            ->limit((int) $limit)
            ->get($this->table)
            ->result();
    }

    public function save_manus_publish_started($id, $website_id)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, [
                'provider_website_id' => $website_id,
                'status'              => 'publishing',
            ]);
    }

    // -----------------------------------------------------------------------
    // Generation lifecycle — Phase 3: Poll publish status
    // -----------------------------------------------------------------------

    public function get_publishing_manus($limit = 5)
    {
        return $this->db
            ->where('status', 'publishing')
            ->where('provider', 'manus')
            ->where('provider_website_id IS NOT NULL', null, false)
            ->order_by('dateupdated', 'ASC')
            ->limit((int) $limit)
            ->get($this->table)
            ->result();
    }

    public function mark_manus_complete($id, $url)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->set('generated_at', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, [
                'status'           => 'review_required',
                'preview_url'      => $url,
                'generation_error' => null,
            ]);
    }

    // -----------------------------------------------------------------------
    // Generation lifecycle — Anthropic (synchronous)
    // -----------------------------------------------------------------------

    public function mark_generation_success($id, array $result, $provider_name = 'anthropic', $preview_url = null)
    {
        $fields = [
            'status'            => 'review_required',
            'provider'          => $provider_name,
            'model_used'        => $result['model']  ?? null,
            'generation_result' => $result['result'] ?? null,
            'generation_error'  => null,
        ];

        if ($preview_url !== null) {
            $fields['preview_url'] = $preview_url;
        }

        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->set('generated_at', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, $fields);
    }

    public function mark_generation_failed($id, $error_message)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, [
                'status'           => 'failed',
                'generation_error' => $error_message,
            ]);
    }

    // -----------------------------------------------------------------------
    // Approval
    // -----------------------------------------------------------------------

    public function mark_approved($id, $staff_id)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->set('approved_at', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, [
                'status'      => 'approved',
                'approved_by' => (int) $staff_id,
            ]);

        return $this->db->affected_rows() > 0;
    }

    // -----------------------------------------------------------------------
    // Admin notification tracking
    // -----------------------------------------------------------------------

    public function get_websites_pending_admin_notification()
    {
        return $this->db
            ->where('status', 'review_required')
            ->where('admin_notified_at IS NULL', null, false)
            ->get($this->table)
            ->result();
    }

    public function mark_admin_notified($id)
    {
        $this->db
            ->set('admin_notified_at', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table);
    }

    // -----------------------------------------------------------------------
    // View tracking
    // -----------------------------------------------------------------------

    public function record_view($id, $device_type = 'unknown')
    {
        $website = $this->get($id);
        if (!$website) {
            return;
        }

        $update = [
            'view_count'      => ((int) $website->view_count) + 1,
            'last_viewed_at'  => date('Y-m-d H:i:s'),
            'last_device_type' => $device_type,
        ];

        if (empty($website->first_viewed_at)) {
            $update['first_viewed_at']  = date('Y-m-d H:i:s');
            $update['first_device_type'] = $device_type;
        }

        // Advance status to viewed if currently sent/approved
        if (in_array($website->status, ['sent', 'approved'])) {
            $update['status'] = 'viewed';
        }

        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, $update);
    }

    // -----------------------------------------------------------------------
    // Subscription lifecycle
    // -----------------------------------------------------------------------

    public function get_sites_for_subscription_check()
    {
        return $this->db
            ->where('subscription_id IS NOT NULL', null, false)
            ->get($this->site_table)
            ->result();
    }

    // -----------------------------------------------------------------------
    // Queuing
    // -----------------------------------------------------------------------

    public function queue_for_generation($id)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->where_in('status', ['new', 'pending', 'review_required', 'failed'])
            ->update($this->table, [
                'status'              => 'pending_generation',
                'provider'            => null,
                'provider_project_id' => null,
                'provider_website_id' => null,
                'generation_error'    => null,
            ]);

        return $this->db->affected_rows() > 0;
    }

    public function queue_for_generation_with_provider($id, $provider)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->where('status', 'failed')
            ->update($this->table, [
                'status'              => 'pending_generation',
                'provider'            => $provider,
                'provider_project_id' => null,
                'provider_website_id' => null,
                'generation_error'    => null,
            ]);

        return $this->db->affected_rows() > 0;
    }

    // -----------------------------------------------------------------------
    // Preview management
    // -----------------------------------------------------------------------

    public function clear_preview_url($id)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, ['preview_url' => null]);
    }

    public function set_preview_url($id, $url)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, ['preview_url' => $url]);
    }

    public function save_html($id, $html)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, ['generation_result' => $html]);
    }

    public function lock_for_modification($id)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->where_in('status', ['review_required', 'approved', 'sent', 'viewed'])
            ->update($this->table, ['status' => 'modifying']);

        return $this->db->affected_rows() > 0;
    }

    public function unlock_modification($id)
    {
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->where('status', 'modifying')
            ->update($this->table, ['status' => 'review_required']);
    }

    // -----------------------------------------------------------------------
    // Stuck record recovery
    // -----------------------------------------------------------------------

    public function rescue_stuck()
    {
        $this->db
            ->where('status', 'generating')
            ->where('provider_project_id IS NULL', null, false)
            ->where('dateupdated < DATE_SUB(NOW(), INTERVAL 10 MINUTE)', null, false)
            ->set('dateupdated', 'NOW()', false)
            ->update($this->table, [
                'status'           => 'failed',
                'generation_error' => 'Generation timed out before reaching the provider.',
            ]);

        $this->db
            ->where('status', 'generating')
            ->where('provider', 'manus')
            ->where('provider_project_id IS NOT NULL', null, false)
            ->where('dateupdated < DATE_SUB(NOW(), INTERVAL 90 MINUTE)', null, false)
            ->set('dateupdated', 'NOW()', false)
            ->update($this->table, [
                'status'           => 'failed',
                'generation_error' => 'Manus generation did not complete within 90 minutes.',
            ]);

        $this->db
            ->where('status', 'publishing')
            ->where('dateupdated < DATE_SUB(NOW(), INTERVAL 30 MINUTE)', null, false)
            ->set('dateupdated', 'NOW()', false)
            ->update($this->table, [
                'status'           => 'failed',
                'generation_error' => 'Website publishing timed out after 30 minutes.',
            ]);

        $this->db
            ->where('status', 'modifying')
            ->where('dateupdated < DATE_SUB(NOW(), INTERVAL 10 MINUTE)', null, false)
            ->set('dateupdated', 'NOW()', false)
            ->update($this->table, ['status' => 'review_required']);
    }

    // -----------------------------------------------------------------------
    // Token lookup — used by public runtime endpoint
    // -----------------------------------------------------------------------

    public function get_by_token($token)
    {
        return $this->db
            ->where('preview_token', $token)
            ->get($this->table)
            ->row();
    }

    // -----------------------------------------------------------------------
    // Prospect conversations
    // -----------------------------------------------------------------------

    public function save_conversation($data)
    {
        $table = db_prefix() . 'pitchsnap_conversations';
        $this->db->insert($table, $data);
        $id = $this->db->insert_id();
        return $id ? (int) $id : false;
    }

    public function get_conversations($website_id)
    {
        return $this->db
            ->where('redesign_id', (int) $website_id)
            ->order_by('created_at', 'ASC')
            ->get(db_prefix() . 'pitchsnap_conversations')
            ->result_array();
    }

    // -----------------------------------------------------------------------
    // Site CRUD
    // -----------------------------------------------------------------------

    public function get_site_by_id($id)
    {
        return $this->db
            ->get_where($this->site_table, ['id' => (int) $id])
            ->row();
    }

    public function get_site_by_token($token)
    {
        return $this->db
            ->where('site_token', $token)
            ->get($this->site_table)
            ->row();
    }

    public function get_site_by_website_id($website_id)
    {
        return $this->db
            ->where('source_website_id', (int) $website_id)
            ->limit(1)
            ->get($this->site_table)
            ->row();
    }

    public function create_site($data)
    {
        if (empty($data['dateadded'])) {
            $data['dateadded'] = date('Y-m-d H:i:s');
        }
        if (empty($data['status'])) {
            $data['status'] = 'preview';
        }

        $this->db->insert($this->site_table, $data);
        $id = $this->db->insert_id();
        return $id ? (int) $id : false;
    }

    public function update_site($id, $data)
    {
        if (empty($data['dateupdated'])) {
            $data['dateupdated'] = date('Y-m-d H:i:s');
        }
        $this->db->where('id', (int) $id)->update($this->site_table, $data);
        return $this->db->affected_rows() > 0;
    }

    public function is_slug_available($slug)
    {
        return $this->db->where('domain', 'clickfuzz.com/sites/' . $slug)->count_all_results($this->site_table) === 0;
    }

    // -----------------------------------------------------------------------
    // Site-domain mapping helpers (tblpitchsnap_site_domains)
    // -----------------------------------------------------------------------

    public function get_site_domain_by_hostname($hostname)
    {
        return $this->db->where('hostname', $hostname)
                        ->get($this->domain_table)
                        ->row();
    }

    public function get_platform_domain_for_site($site_id)
    {
        return $this->db->where('site_id', (int) $site_id)
                        ->where('domain_type', 'platform')
                        ->where('is_primary', 1)
                        ->get($this->domain_table)
                        ->row();
    }

    public function hostname_available($hostname)
    {
        return $this->db->where('hostname', $hostname)
                        ->count_all_results($this->domain_table) === 0;
    }

    public function create_site_domain($data)
    {
        if (empty($data['dateadded'])) {
            $data['dateadded'] = date('Y-m-d H:i:s');
        }
        $this->db->insert($this->domain_table, $data);
        $id = $this->db->insert_id();
        return $id ? (int) $id : false;
    }

    // -----------------------------------------------------------------------
    // Lead source / status helpers
    // -----------------------------------------------------------------------

    public function get_or_create_source($name)
    {
        $prefix = db_prefix();
        $this->db->where('name', $name);
        $source = $this->db->get($prefix . 'leads_sources')->row();
        if ($source) {
            return (int) $source->id;
        }
        $this->db->insert($prefix . 'leads_sources', ['name' => $name]);
        $new_id = (int) $this->db->insert_id();
        if ($new_id) {
            log_activity('PitchSnap: Lead source created [Name: ' . $name . ']');
        }
        return $new_id;
    }

    public function get_default_status_id()
    {
        $status_id = get_option('leads_default_status');
        if (is_numeric($status_id) && $status_id > 0) {
            return (int) $status_id;
        }
        $row = $this->db
            ->order_by('statusorder', 'ASC')
            ->limit(1)
            ->get(db_prefix() . 'leads_status')
            ->row();

        return $row ? (int) $row->id : 1;
    }

    // -----------------------------------------------------------------------
    // Version management
    // -----------------------------------------------------------------------

    public function update($id, $data)
    {
        if (empty($data['dateupdated'])) {
            $data['dateupdated'] = date('Y-m-d H:i:s');
        }
        $this->db->where('id', (int) $id)->update($this->table, $data);
        return $this->db->affected_rows() > 0;
    }

    public function get_versions_for_lead($lead_id)
    {
        return $this->db
            ->where('lead_id', (int) $lead_id)
            ->order_by('is_primary', 'DESC')
            ->order_by('id', 'DESC')
            ->get($this->table)
            ->result();
    }

    public function get_primary_for_lead($lead_id)
    {
        $row = $this->db
            ->where('lead_id', (int) $lead_id)
            ->where('is_primary', 1)
            ->limit(1)
            ->get($this->table)
            ->row();
        if ($row) return $row;
        // Fallback: highest ID for lead if no primary set
        return $this->db
            ->where('lead_id', (int) $lead_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get($this->table)
            ->row();
    }

    public function set_primary_version($id, $lead_id)
    {
        $this->db->where('lead_id', (int) $lead_id)->update($this->table, ['is_primary' => 0]);
        $this->db
            ->set('dateupdated', 'NOW()', false)
            ->where('id', (int) $id)
            ->update($this->table, ['is_primary' => 1]);
        return $this->db->affected_rows() > 0;
    }

    public function delete_redesign($id)
    {
        $website = $this->get($id);
        if (!$website || !empty($website->is_primary)) {
            return false;
        }
        return $this->db->delete($this->table, ['id' => (int) $id]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function generate_token()
    {
        for ($i = 0; $i < 5; $i++) {
            $token    = bin2hex(random_bytes(32));
            $existing = $this->db->where('preview_token', $token)->count_all_results($this->table);
            if ($existing === 0) {
                return $token;
            }
        }
        return bin2hex(random_bytes(28)) . sprintf('%08x', time());
    }

    // -----------------------------------------------------------------------
    // Agreement CRUD
    // -----------------------------------------------------------------------

    public function get_agreement_by_site($site_id)
    {
        return $this->db
            ->where('site_id', (int) $site_id)
            ->order_by('accepted_at', 'DESC')
            ->limit(1)
            ->get($this->agreement_table)
            ->row();
    }

    public function create_agreement($data)
    {
        $this->db->insert($this->agreement_table, $data);
        $id = $this->db->insert_id();
        return $id ? (int) $id : false;
    }

    // -----------------------------------------------------------------------
    // Activity log
    // -----------------------------------------------------------------------

    public function create_log($context, $message, $data = null)
    {
        $count = $this->db->count_all($this->log_table);
        if ($count >= 500) {
            $this->db->query(
                'DELETE FROM `' . $this->log_table . '` ORDER BY id ASC LIMIT ' . ($count - 490)
            );
        }
        $this->db->insert($this->log_table, [
            'context'    => substr((string) $context, 0, 50),
            'message'    => $message,
            'data_json'  => $data !== null ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function get_logs($limit = 200)
    {
        return $this->db
            ->order_by('id', 'DESC')
            ->limit((int) $limit)
            ->get($this->log_table)
            ->result_array();
    }

    public function clear_logs()
    {
        $this->db->truncate($this->log_table);
    }

    // -----------------------------------------------------------------------
    // Full profile delete — removes all PitchSnap data for a lead
    // -----------------------------------------------------------------------

    public function delete_lead_profile($lead_id)
    {
        $lead_id = (int) $lead_id;
        if (!$lead_id) {
            return false;
        }

        $redesigns = $this->db->where('lead_id', $lead_id)->get($this->table)->result();
        if (empty($redesigns)) {
            return false;
        }

        $redesign_ids = array_map(function ($r) { return (int) $r->id; }, $redesigns);

        // Remove preview files from disk
        $base_dir  = dirname(FCPATH) . '/previews';
        $real_base = realpath($base_dir);
        foreach ($redesigns as $r) {
            if (empty($r->preview_token) || !preg_match('/^[a-f0-9]{64}$/', $r->preview_token)) {
                continue;
            }
            $preview_dir = $base_dir . '/' . $r->preview_token;
            if (!is_dir($preview_dir)) {
                continue;
            }
            $real_dir = realpath($preview_dir);
            if ($real_base && $real_dir && strpos($real_dir . '/', $real_base . '/') === 0) {
                foreach (@scandir($preview_dir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') continue;
                    @unlink($preview_dir . '/' . $entry);
                }
                @rmdir($preview_dir);
            }
        }

        // Delete conversations
        $conv_table = db_prefix() . 'pitchsnap_conversations';
        if ($this->db->table_exists($conv_table)) {
            $this->db->where_in('redesign_id', $redesign_ids)->delete($conv_table);
        }

        // Collect site records for this lead's redesigns
        $sites    = $this->db->where_in('source_website_id', $redesign_ids)->get($this->site_table)->result();
        $site_ids = array_map(function ($s) { return (int) $s->id; }, $sites);

        // Remove published site directories from disk
        $sites_base      = dirname(FCPATH) . '/sites';
        $real_sites_base = realpath($sites_base);
        foreach ($sites as $s) {
            $domain = $s->domain ?? '';
            $after  = strstr($domain, '/sites/');
            if (!$after) { continue; }
            $slug = ltrim($after, '/sites/');
            if (!$slug || !preg_match('/^[a-z0-9\-]+$/', $slug)) { continue; }
            $site_dir = $sites_base . '/' . $slug;
            if (!is_dir($site_dir)) { continue; }
            $real_dir = realpath($site_dir);
            if ($real_sites_base && $real_dir && strpos($real_dir . '/', $real_sites_base . '/') === 0) {
                foreach (@scandir($site_dir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') { continue; }
                    @unlink($site_dir . '/' . $entry);
                }
                @rmdir($site_dir);
            }
        }

        // Delete agreements
        if (!empty($site_ids) && $this->db->table_exists($this->agreement_table)) {
            $this->db->where_in('site_id', $site_ids)->delete($this->agreement_table);
        }

        // Delete sites
        if (!empty($site_ids)) {
            $this->db->where_in('id', $site_ids)->delete($this->site_table);
        }

        // Delete all redesign records for this lead
        $this->db->where('lead_id', $lead_id)->delete($this->table);

        log_activity('PitchSnap: Website profile deleted [Lead ID: ' . $lead_id . ', Versions: ' . count($redesign_ids) . ']');
        return true;
    }
}
