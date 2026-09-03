<?php
defined('BASEPATH') or exit('No direct script access allowed');

if (!function_exists('e')) {
    function e($value, $doubleEncode = true) {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
    }
}

class Pitchsnap_runtime extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('App_object_cache');
        $this->load->helper('url');
    }

    public function script()
    {
        $chat_url     = rtrim(base_url('pitchsnap/chat'), '/');
        $purchase_url = rtrim(base_url('pitchsnap/purchase'), '/');
        $raw_video = get_option('pitchsnap_video_demo_url') ?: '';
        $video_embed_url = $raw_video ? $this->_normalize_video_url($raw_video) : '';
        $price_display = '$' . number_format((float)(get_option('pitchsnap_price') ?: '295.00'), 0, '.', ',');
        ob_start();
        include FCPATH . 'modules/pitchsnap/views/runtime_js.php';
        $js = ob_get_clean();
        $this->output
            ->set_content_type('application/javascript')
            ->set_header('Cache-Control: public, max-age=3600')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_output($js);
    }

    public function chat()
    {
        $this->_cors();
        if ($this->input->server('REQUEST_METHOD') === 'OPTIONS') {
            $this->output->set_status_header(204)->set_output('');
            return;
        }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            return $this->_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->_json(['success' => false, 'error' => 'Invalid request body.'], 400);
        }
        $token       = trim($body['token']  ?? '');
        $quick_reply = trim($body['reply']  ?? '');
        $change_text = trim($body['change'] ?? '');
        if ($token === '') {
            return $this->_json(['success' => false, 'error' => 'Missing token.'], 400);
        }
        $allowed = ['like', 'change', 'not_for_me'];
        if (!in_array($quick_reply, $allowed, true)) {
            return $this->_json(['success' => false, 'error' => 'Invalid reply value.'], 400);
        }
        if (mb_strlen($change_text) > 1000) {
            return $this->_json(['success' => false, 'error' => 'Message too long (1000 character maximum).'], 400);
        }
        $this->_load_model();
        $website = $this->pitchsnap_model->get_by_token($token);
        if (!$website) {
            return $this->_json(['success' => false, 'error' => 'Invalid token.'], 404);
        }
        if ($change_text !== '') {
            $change_text = strip_tags(mb_substr($change_text, 0, 1000));
        }
        $saved = $this->pitchsnap_model->save_conversation([
            'redesign_id'    => (int) $website->id,
            'quick_reply'    => $quick_reply,
            'change_request' => ($quick_reply === 'change' && $change_text !== '') ? $change_text : null,
            'ip_address'     => $this->input->ip_address(),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        if (!$saved) {
            return $this->_json(['success' => false, 'error' => 'Could not save response. Please try again.'], 500);
        }
        if (!empty($website->lead_id)) {
            $this->load->model('leads_model');
            $this->leads_model->log_lead_activity(
                (int) $website->lead_id,
                $this->_activity_text($quick_reply, $change_text)
            );
        }
        log_activity('PitchSnap: Prospect chat response [Website ID: ' . $website->id . ', Reply: ' . $quick_reply . ']');
        return $this->_json(['success' => true]);
    }

    public function purchase()
    {
        $this->_cors();
        if ($this->input->server('REQUEST_METHOD') === 'OPTIONS') {
            $this->output->set_status_header(204)->set_output('');
            return;
        }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            return $this->_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->_json(['success' => false, 'error' => 'Invalid request body.'], 400);
        }
        $site_token     = trim($body['site_token']     ?? '');
        $redesign_token = trim($body['redesign_token'] ?? '');
        $this->_load_model();
        $site = null;
        if ($site_token && preg_match('/^[a-f0-9]{64}$/', $site_token)) {
            $site = $this->pitchsnap_model->get_site_by_token($site_token);
        }
        if (!$site && $redesign_token && preg_match('/^[a-f0-9]{64}$/', $redesign_token)) {
            $website = $this->pitchsnap_model->get_by_token($redesign_token);
            if ($website) {
                $site = $this->pitchsnap_model->get_site_by_website_id($website->id);
                if (!$site) {
                    $site_id = $this->pitchsnap_model->create_site([
                        'source_website_id' => (int) $website->id,
                        'source_lead_id'    => $website->lead_id ? (int) $website->lead_id : null,
                        'site_token'        => bin2hex(random_bytes(32)),
                        'status'            => 'preview',
                        'dateadded'         => date('Y-m-d H:i:s'),
                    ]);
                    if ($site_id) {
                        $site = $this->pitchsnap_model->get_site_by_id($site_id);
                    }
                }
            }
        }
        if (!$site) {
            return $this->_json(['success' => false, 'error' => 'Site not found. Please refresh the page and try again.'], 404);
        }
        try {
        if ($site->invoice_id) {
            $this->load->model('invoices_model');
            $url = $this->_create_stripe_checkout_url((int) $site->invoice_id);
            $this->_log('purchase', 'Idempotency: invoice exists', ['invoice_id' => $site->invoice_id, 'url' => $url]);
            if ($url) {
                return $this->_json(['success' => true, 'redirect' => $url]);
            }
            $this->pitchsnap_model->update_site($site->id, ['invoice_id' => null]);
            $site->invoice_id = null;
        }
        if (!empty($site->subscription_id)) {
            $sub_row = $this->db->select('id, hash, status')->where('id', (int) $site->subscription_id)->get(db_prefix() . 'subscriptions')->row();
            if ($sub_row && strtolower($sub_row->status) !== 'canceled') {
                if (strtolower($sub_row->status) === 'active') {
                    return $this->_json(['success' => true, 'agreement_url' => site_url('pitchsnap/agreement/' . $site->site_token)]);
                }
                $url = $this->_create_stripe_subscription_url((int) $site->subscription_id, $sub_row->hash, (string) $site->site_token);
                $this->_log('purchase', 'Idempotency: subscription pending payment', ['sub_id' => $site->subscription_id, 'url' => $url]);
                if ($url) {
                    return $this->_json(['success' => true, 'redirect' => $url]);
                }
            }
            $this->pitchsnap_model->update_site($site->id, ['subscription_id' => null]);
            $site->subscription_id = null;
        }
        if ($site->client_id) {
            return $this->_json([
                'success'       => true,
                'agreement_url' => site_url('pitchsnap/agreement/' . $site->site_token),
            ]);
        }
        if (!$site->source_lead_id) {
            return $this->_json(['success' => false, 'error' => 'No lead associated with this site.'], 422);
        }
        $this->load->model('leads_model');
        $lead = $this->db->get_where(db_prefix() . 'leads', ['id' => (int) $site->source_lead_id])->row();
        if (!$lead) {
            return $this->_json(['success' => false, 'error' => 'Lead not found.'], 404);
        }
        $name_parts = explode(' ', trim($lead->name), 2);
        $firstname  = $name_parts[0] ?? $lead->name;
        $lastname   = $name_parts[1] ?? '';
        $client_data = [
            'company'     => $lead->company ?: $lead->name,
            'phonenumber' => $lead->phonenumber ?? '',
            'address'     => $lead->address     ?? '',
            'city'        => $lead->city         ?? '',
            'state'       => $lead->state        ?? '',
            'zip'         => $lead->zip          ?? '',
            'country'     => $lead->country      ?? '',
            'website'     => $lead->website      ?? '',
            'leadid'      => (int) $lead->id,
            'firstname'   => $firstname,
            'lastname'    => $lastname,
            'email'       => $lead->email,
            'donotsendwelcomeemail' => true,
            'is_primary'  => 1,
        ];
        $client_id = $this->clients_model->add($client_data, true);
        if (!$client_id) {
            return $this->_json(['success' => false, 'error' => 'Could not create customer record.'], 500);
        }
        $this->db->where('id', (int) $lead->id)->update(db_prefix() . 'leads', [
            'date_converted' => date('Y-m-d H:i:s'),
        ]);
        if (method_exists($this->leads_model, 'log_lead_activity')) {
            $this->leads_model->log_lead_activity(
                (int) $lead->id,
                'Lead converted to customer via PitchSnap Purchase Plan.'
            );
        }
        log_activity('PitchSnap: Lead converted to customer [Lead ID: ' . $lead->id . ', Client ID: ' . $client_id . ']');
        $this->pitchsnap_model->update_site($site->id, ['client_id' => $client_id]);
        return $this->_json([
            'success'       => true,
            'agreement_url' => site_url('pitchsnap/agreement/' . $site->site_token),
        ]);
        } catch (\Throwable $e) {
            return $this->_json(['success' => false, 'error' => 'purchase: ' . $e->getMessage()], 500);
        }
    }

    public function agreement($token = '')
    {
        $token = trim($token);
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            show_404();
            return;
        }
        $this->_load_model();
        $site = $this->pitchsnap_model->get_site_by_token($token);
        if (!$site || !$site->client_id) {
            show_404();
            return;
        }
        if ($site->invoice_id) {
            $agreement = $this->pitchsnap_model->get_agreement_by_site($site->id);
            if ($agreement) {
                $this->load->model('invoices_model');
                $url = $this->_invoice_payment_url((int) $site->invoice_id);
                if ($url) {
                    redirect($url);
                    return;
                }
            }
        }
        $version    = get_option('pitchsnap_agreement_version') ?: '1.0';
        $text       = get_option('pitchsnap_agreement_text')    ?: $this->_default_agreement_text();
        $accept_url = base_url('pitchsnap/accept_agreement');
        ob_start();
        include FCPATH . 'modules/pitchsnap/views/agreement_page.php';
        $html = ob_get_clean();
        $this->output
            ->set_content_type('text/html')
            ->set_header('X-Robots-Tag: noindex, nofollow')
            ->set_output($html);
    }

    public function onboarding($token = '')
    {
        $token = trim($token);
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            show_404();
            return;
        }
        $this->_load_model();
        $link = $this->pitchsnap_model->get_onboarding_link_by_token($token);
        if (!$link || !in_array($link['status'], ['active', 'completed'], true)) {
            show_404();
            return;
        }
        $flow = $this->pitchsnap_model->get_flow($link['flow_id']);
        if (!$flow) {
            show_404();
            return;
        }
        $sections = $this->pitchsnap_model->get_sections_for_flow((int) $link['flow_id']);
        foreach ($sections as &$sec) {
            $sec['questions'] = $this->pitchsnap_model->get_questions_for_section((int) $sec['id']);
        }
        unset($sec);

        $_existing_data = [];
        foreach ($this->pitchsnap_model->get_site_data((int) $link['site_id']) as $_r) {
            $_existing_data[$_r['data_key']] = $_r['value'];
        }

        ob_start();
        include FCPATH . 'modules/pitchsnap/views/onboarding_wizard.php';
        $html = ob_get_clean();
        $this->output
            ->set_content_type('text/html')
            ->set_header('X-Robots-Tag: noindex, nofollow')
            ->set_output($html);
    }

    public function onboarding_embed()
    {
        $token = trim($this->input->get('token') ?? '');
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            show_404();
            return;
        }
        $this->_load_model();
        $link = $this->pitchsnap_model->get_onboarding_link_by_token($token);
        if (!$link) {
            show_404();
            return;
        }
        if (!in_array($link['status'], ['active', 'completed'], true)) {
            $this->output
                ->set_content_type('text/html')
                ->set_header('X-Robots-Tag: noindex, nofollow')
                ->set_header('X-Frame-Options: SAMEORIGIN')
                ->set_output($this->_ob_status_page('This onboarding link is no longer active.'));
            return;
        }
        $flow = $this->pitchsnap_model->get_flow($link['flow_id']);
        if (!$flow) {
            show_404();
            return;
        }
        $sections = $this->pitchsnap_model->get_sections_for_flow((int) $link['flow_id']);
        foreach ($sections as &$sec) {
            $sec['questions'] = $this->pitchsnap_model->get_questions_for_section((int) $sec['id']);
        }
        unset($sec);

        $_existing_data = [];
        foreach ($this->pitchsnap_model->get_site_data((int) $link['site_id']) as $_r) {
            $_existing_data[$_r['data_key']] = $_r['value'];
        }

        ob_start();
        include FCPATH . 'modules/pitchsnap/views/onboarding_wizard.php';
        $html = ob_get_clean();
        $this->output
            ->set_content_type('text/html')
            ->set_header('X-Robots-Tag: noindex, nofollow')
            ->set_header('X-Frame-Options: SAMEORIGIN')
            ->set_output($html);
    }

    public function onboarding_loader_js()
    {
        $embed_url = rtrim(base_url('pitchsnap/onboarding_embed'), '/');
        ob_start();
        include FCPATH . 'modules/pitchsnap/views/onboarding_loader_js.php';
        $js = ob_get_clean();
        $this->output
            ->set_content_type('application/javascript')
            ->set_header('Cache-Control: public, max-age=3600')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_output($js);
    }

    public function onboarding_submit()
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            return $this->_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->_json(['success' => false, 'error' => 'Invalid request body.'], 400);
        }
        $token = trim((string) ($body['token'] ?? ''));
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->_json(['success' => false, 'error' => 'Invalid token.'], 400);
        }
        $this->_load_model();
        $link = $this->pitchsnap_model->get_onboarding_link_by_token($token);
        if (!$link || !in_array($link['status'], ['active', 'completed'], true)) {
            return $this->_json(['success' => false, 'error' => 'This onboarding link is no longer active.'], 403);
        }
        $site_id = (int) $link['site_id'];
        $flow_id = (int) $link['flow_id'];

        // Load all questions server-side
        $sections = $this->pitchsnap_model->get_sections_for_flow($flow_id);
        $all_questions = [];
        foreach ($sections as $sec) {
            $qs = $this->pitchsnap_model->get_questions_for_section((int) $sec['id']);
            foreach ($qs as $q) {
                $all_questions[(int) $q['id']] = $q;
            }
        }

        // Map submitted answers (keys like "q_123")
        $submitted = [];
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
        foreach ($answers as $key => $value) {
            if (preg_match('/^q_(\d+)$/', (string) $key, $m)) {
                $submitted[(int) $m[1]] = $value;
            }
        }

        // Evaluate visibility using same logic as the wizard JS
        $visible = [];
        foreach ($all_questions as $qid => $q) {
            if (empty($q['condition_question_id'])) {
                $visible[$qid] = true;
                continue;
            }
            $ctrl_id  = (int) $q['condition_question_id'];
            $op       = (string) ($q['condition_operator'] ?? '');
            $expected = (string) ($q['condition_value'] ?? '');
            $raw      = $submitted[$ctrl_id] ?? null;
            $ctrl_val = is_array($raw) ? implode(',', array_map('strval', $raw)) : (string) ($raw ?? '');
            $show = false;
            if ($op === 'equals')     { $show = $ctrl_val === $expected; }
            if ($op === 'not_equals') { $show = $ctrl_val !== $expected; }
            if ($op === 'contains')   { $show = strpos($ctrl_val, $expected) !== false; }
            $visible[$qid] = $show;
        }

        // Required-field validation (visible questions only)
        foreach ($all_questions as $qid => $q) {
            if (!($visible[$qid] ?? false)) { continue; }
            if (!$q['required']) { continue; }
            if ($q['field_type'] === 'file') {
                // File questions are saved via onboarding_file_upload; check site_data directly
                $data_key = trim((string) ($q['data_key'] ?? ''));
                if ($data_key !== '') {
                    $row = $this->db->get_where(db_prefix() . 'pitchsnap_site_data',
                        ['site_id' => $site_id, 'data_key' => $data_key])->row_array();
                    $ref = ($row && $row['value']) ? @json_decode($row['value'], true) : null;
                    if (!is_array($ref) || ($ref['_type'] ?? '') !== 'ob_file' || empty($ref['filename'])) {
                        $label = $q['label'] ?: 'A required field';
                        return $this->_json(['success' => false, 'error' => $label . ' is required.'], 422);
                    }
                }
                continue;
            }
            $raw = $submitted[$qid] ?? null;
            $str = is_array($raw)
                ? implode('', array_map('strval', $raw))
                : trim((string) ($raw ?? ''));
            if ($str === '') {
                $label = $q['label'] ?: 'A required field';
                return $this->_json(['success' => false, 'error' => $label . ' is required.'], 422);
            }
        }

        // Save answers for visible questions that have a data_key
        foreach ($all_questions as $qid => $q) {
            if (!($visible[$qid] ?? false)) { continue; }
            $data_key = trim((string) ($q['data_key'] ?? ''));
            if ($data_key === '') { continue; }
            $value = $this->_ob_normalize_value($q, $submitted[$qid] ?? null);
            if ($value === null) { continue; } // file questions saved via upload endpoint
            $this->pitchsnap_model->upsert_site_data($site_id, $data_key, $value);
        }

        $update_link = ['status' => 'completed'];
        if (empty($link['completed_at'])) {
            $update_link['completed_at'] = date('Y-m-d H:i:s');
        }
        $this->db->where('id', (int) $link['id'])
            ->update(db_prefix() . 'pitchsnap_onboarding_links', $update_link);

        log_activity('PitchSnap: Onboarding submitted [Link ID: ' . $link['id'] . ', Site ID: ' . $site_id . ']');
        return $this->_json(['success' => true]);
    }

    public function onboarding_save_progress()
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            return $this->_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->_json(['success' => false, 'error' => 'Invalid request body.'], 400);
        }
        $token = trim((string) ($body['token'] ?? ''));
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->_json(['success' => false, 'error' => 'Invalid token.'], 400);
        }
        $this->_load_model();
        $link = $this->pitchsnap_model->get_onboarding_link_by_token($token);
        if (!$link || !in_array($link['status'], ['active', 'completed'], true)) {
            return $this->_json(['success' => false, 'error' => 'This onboarding link is no longer active.'], 403);
        }
        $site_id = (int) $link['site_id'];
        $flow_id = (int) $link['flow_id'];

        // Load questions server-side to validate submitted q_ids belong to this flow
        $sections = $this->pitchsnap_model->get_sections_for_flow($flow_id);
        $all_questions = [];
        foreach ($sections as $sec) {
            $qs = $this->pitchsnap_model->get_questions_for_section((int) $sec['id']);
            foreach ($qs as $q) {
                $all_questions[(int) $q['id']] = $q;
            }
        }

        // Upsert only submitted visible answers; skip unknown q_ids; no required-field check
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
        foreach ($answers as $key => $raw) {
            if (!preg_match('/^q_(\d+)$/', (string) $key, $m)) { continue; }
            $qid = (int) $m[1];
            if (!isset($all_questions[$qid])) { continue; }
            $data_key = trim((string) ($all_questions[$qid]['data_key'] ?? ''));
            if ($data_key === '') { continue; }
            $value = $this->_ob_normalize_value($all_questions[$qid], $raw);
            if ($value === null) { continue; } // file questions saved via upload endpoint
            $this->pitchsnap_model->upsert_site_data($site_id, $data_key, $value);
        }

        return $this->_json(['success' => true]);
    }

    public function onboarding_file_upload()
    {
        // No CSRF — this endpoint is added to $app_csrf_exclude_uris on production
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            return $this->_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }

        $token       = trim((string) ($this->input->post('token') ?? ''));
        $question_id = (int) $this->input->post('question_id');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->_json(['success' => false, 'error' => 'Invalid token.'], 400);
        }
        if (!$question_id) {
            return $this->_json(['success' => false, 'error' => 'Invalid question.'], 400);
        }

        $this->_load_model();
        $link = $this->pitchsnap_model->get_onboarding_link_by_token($token);
        if (!$link || !in_array($link['status'], ['active', 'completed'], true)) {
            return $this->_json(['success' => false, 'error' => 'This onboarding link is no longer active.'], 403);
        }

        $site_id = (int) $link['site_id'];
        $flow_id = (int) $link['flow_id'];

        // Verify the question belongs to this flow and is of type 'file'
        $sections = $this->pitchsnap_model->get_sections_for_flow($flow_id);
        $question  = null;
        foreach ($sections as $sec) {
            $qs = $this->pitchsnap_model->get_questions_for_section((int) $sec['id']);
            foreach ($qs as $q) {
                if ((int) $q['id'] === $question_id) {
                    $question = $q;
                    break 2;
                }
            }
        }
        if (!$question) {
            return $this->_json(['success' => false, 'error' => 'Invalid question.'], 400);
        }
        if ($question['field_type'] !== 'file') {
            return $this->_json(['success' => false, 'error' => 'This question does not accept file uploads.'], 400);
        }

        $data_key = trim((string) ($question['data_key'] ?? ''));
        if ($data_key === '') {
            return $this->_json(['success' => false, 'error' => 'Question has no data key.'], 400);
        }

        require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_media_helper.php';
        $result = clickfuzz_web_upload_ob_doc($site_id);
        if (!$result['success']) {
            return $this->_json(['success' => false, 'error' => $result['error']], 422);
        }

        $ref = json_encode([
            '_type'         => 'ob_file',
            'filename'      => $result['filename'],
            'original_name' => $result['original_name'],
            'mime_type'     => $result['mime_type'],
            'size'          => $result['size'],
        ]);

        $this->pitchsnap_model->upsert_site_data($site_id, $data_key, $ref);

        // Extraction: if this question has extraction mappings, attempt document AI extraction
        $extraction_response = null;
        $extraction_map = !empty($question['extraction_map_json'])
            ? json_decode($question['extraction_map_json'], true)
            : null;

        if (is_array($extraction_map) && !empty($extraction_map)) {
            // Collect which extraction fields are requested and build field→data_key map
            $field_to_dk = [];
            foreach ($extraction_map as $row) {
                $ef = $row['extraction_field'] ?? '';
                $dk = $row['data_key']         ?? '';
                if ($ef && $dk) { $field_to_dk[$ef] = $dk; }
            }

            if (!empty($field_to_dk)) {
                $file_path = clickfuzz_web_ob_doc_dir($site_id) . '/' . $result['filename'];
                $this->load->library('pitchsnap_anthropic');
                $ex = $this->pitchsnap_anthropic->extract_document(
                    $file_path,
                    $result['mime_type'],
                    array_keys($field_to_dk)
                );

                if ($ex['success'] && is_array($ex['extracted'])) {
                    $populated = [];
                    foreach ($field_to_dk as $ef => $target_dk) {
                        $val = $ex['extracted'][$ef] ?? null;
                        if ($val === null || $val === '') { continue; }
                        // Only write if target is currently empty — do not overwrite customer edits
                        $existing_row = $this->pitchsnap_model->get_site_data_value($site_id, $target_dk);
                        $existing_val = $existing_row ? ($existing_row['value'] ?? '') : '';
                        if ($existing_val !== '' && $existing_val !== null) { continue; }
                        $this->pitchsnap_model->upsert_site_data($site_id, $target_dk, $val);
                        $populated[$target_dk] = $val;
                    }
                    $extraction_response = ['success' => true, 'populated' => $populated];
                } else {
                    $extraction_response = ['success' => false, 'error' => $ex['error'] ?? 'Extraction failed.'];
                }
            }
        }

        $resp = ['success' => true];
        if ($extraction_response !== null) {
            $resp['extraction'] = $extraction_response;
        }
        return $this->_json($resp);
    }

    public function onboarding_phone_search()
    {
        // No CSRF — added to $app_csrf_exclude_uris on production
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            return $this->_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->_json(['success' => false, 'error' => 'Invalid request body.'], 400);
        }

        $token  = trim((string) ($body['token']  ?? ''));
        $search = trim((string) ($body['search'] ?? ''));

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $this->_json(['success' => false, 'error' => 'Invalid token.'], 400);
        }

        $this->_load_model();
        $link = $this->pitchsnap_model->get_onboarding_link_by_token($token);
        if (!$link || !in_array($link['status'], ['active', 'completed'], true)) {
            return $this->_json(['success' => false, 'error' => 'This onboarding link is no longer active.'], 403);
        }

        $location_id = trim((string) get_option('pitchsnap_agency_location_id'));
        if ($location_id === '') {
            return $this->_json(['success' => false, 'unavailable' => true,
                'error' => 'Phone number search is temporarily unavailable. You can enter your preferred number manually, or skip this step and provide it later.']);
        }

        $this->load->library('pitchsnap_ghl');
        $result = $this->pitchsnap_ghl->search_available_numbers($location_id, $search);

        if (!$result['success']) {
            return $this->_json(['success' => false, 'error' => $result['error'] ?? 'Search failed.']);
        }

        return $this->_json(['success' => true, 'numbers' => $result['numbers']]);
    }

    private function _ob_normalize_value($q, $raw)
    {
        $ft = (string) ($q['field_type'] ?? 'text');
        if ($ft === 'file') {
            return null; // file questions are saved via onboarding_file_upload; skip here
        }
        if ($ft === 'phone_number_picker') {
            $val = trim((string) ($raw ?? ''));
            if ($val === '') { return null; }
            $digits = preg_replace('/[^0-9]/', '', $val);
            if (strlen($digits) === 10) { $digits = '1' . $digits; }
            if (!preg_match('/^1[2-9]\d{9}$/', $digits)) { return null; }
            return '+' . $digits;
        }
        if ($ft === 'checkbox') {
            if (is_array($raw)) {
                return json_encode(array_values(array_map('strval', $raw)));
            } elseif (is_string($raw) && $raw !== '') {
                $arr = json_decode($raw, true);
                return is_array($arr) ? json_encode(array_values(array_map('strval', $arr))) : json_encode([]);
            }
            return json_encode([]);
        }
        if ($ft === 'question_builder') {
            $arr = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($arr)) { $arr = []; }
            $allowed_types = ['text','textarea','number','email','phone','select','radio','checkbox','yes_no'];
            $normalized = [];
            foreach ($arr as $item) {
                if (!is_array($item)) { continue; }
                $normalized[] = [
                    'label'   => mb_substr(strip_tags((string) ($item['label'] ?? '')), 0, 500),
                    'type'    => in_array($item['type'] ?? '', $allowed_types, true) ? $item['type'] : 'text',
                    'options' => is_array($item['options'] ?? null)
                        ? array_values(array_map(function ($o) { return mb_substr(strip_tags((string) $o), 0, 200); }, $item['options']))
                        : [],
                ];
            }
            return json_encode($normalized);
        }
        return mb_substr(strip_tags((string) ($raw ?? '')), 0, 2000);
    }

    public function accept_agreement()
    {
        $this->_cors();
        if ($this->input->server('REQUEST_METHOD') === 'OPTIONS') {
            $this->output->set_status_header(204)->set_output('');
            return;
        }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            return $this->_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->_json(['success' => false, 'error' => 'Invalid request body.'], 400);
        }
        $site_token = trim($body['site_token'] ?? '');
        $accepted   = !empty($body['accepted']);
        if (!$site_token || !preg_match('/^[a-f0-9]{64}$/', $site_token)) {
            return $this->_json(['success' => false, 'error' => 'Invalid site token.'], 400);
        }
        if (!$accepted) {
            return $this->_json(['success' => false, 'error' => 'You must accept the agreement to continue.'], 400);
        }
        $this->_load_model();
        $site = $this->pitchsnap_model->get_site_by_token($site_token);
        if (!$site) {
            return $this->_json(['success' => false, 'error' => 'Site not found.'], 404);
        }
        if (!$site->client_id) {
            return $this->_json(['success' => false, 'error' => 'Customer record not found. Please restart the purchase flow.'], 422);
        }
        try {
        $payment_type = get_option('pitchsnap_payment_type') ?: 'onetime';
        $existing_agreement = $this->pitchsnap_model->get_agreement_by_site($site->id);
        $version = get_option('pitchsnap_agreement_version') ?: '1.0';
        if ($payment_type === 'subscription') {
            if ($existing_agreement && !empty($site->subscription_id)) {
                $sub_row = $this->db->select('id, hash, status')->where('id', (int) $site->subscription_id)->get(db_prefix() . 'subscriptions')->row();
                if ($sub_row && strtolower($sub_row->status) !== 'canceled') {
                    $url = $this->_create_stripe_subscription_url((int) $site->subscription_id, $sub_row->hash, $site_token);
                    if ($url) {
                        return $this->_json(['success' => true, 'redirect' => $url]);
                    }
                }
                $this->pitchsnap_model->update_site($site->id, ['subscription_id' => null]);
                $site->subscription_id = null;
            }
            if (!$existing_agreement) {
                $text         = get_option('pitchsnap_agreement_text') ?: $this->_default_agreement_text();
                $agreement_id = $this->pitchsnap_model->create_agreement([
                    'site_id'           => (int) $site->id,
                    'client_id'         => (int) $site->client_id,
                    'agreement_version' => $version,
                    'agreement_hash'    => hash('sha256', $text),
                    'accepted_at'       => date('Y-m-d H:i:s'),
                    'ip_address'        => $this->input->ip_address(),
                ]);
                if (!$agreement_id) {
                    return $this->_json(['success' => false, 'error' => 'Could not record agreement acceptance. Please try again.'], 500);
                }
                log_activity('PitchSnap: Agreement accepted [Site ID: ' . $site->id . ', Client ID: ' . $site->client_id . ', Version: ' . $version . ']');
            }
            if (empty($site->subscription_id)) {
                $sub = $this->_create_pitchsnap_subscription((int) $site->client_id, $version);
                $this->_log('accept_agreement', 'Subscription creation result', [
                    'client_id' => $site->client_id,
                    'sub'       => $sub,
                ]);
                if (!$sub) {
                    return $this->_json(['success' => false, 'error' => 'Agreement accepted but subscription could not be created. Please contact support.'], 500);
                }
                $this->pitchsnap_model->update_site($site->id, ['subscription_id' => $sub['id']]);
                $site->subscription_id = $sub['id'];
                $sub_hash              = $sub['hash'];
            } else {
                $sub_row  = $this->db->select('hash')->where('id', (int) $site->subscription_id)->get(db_prefix() . 'subscriptions')->row();
                $sub_hash = $sub_row ? $sub_row->hash : null;
            }
            if (!$sub_hash) {
                return $this->_json(['success' => false, 'error' => 'Subscription record missing. Please contact support.'], 500);
            }
            $url = $this->_create_stripe_subscription_url((int) $site->subscription_id, $sub_hash, $site_token);
            $this->_log('accept_agreement', 'Stripe subscription URL result', [
                'sub_id' => $site->subscription_id,
                'url'    => $url,
            ]);
            return $this->_json(['success' => true, 'redirect' => $url]);
        } else {
            $this->load->model('invoices_model');
            if ($existing_agreement && $site->invoice_id) {
                $url = $this->_create_stripe_checkout_url((int) $site->invoice_id);
                if ($url) {
                    return $this->_json(['success' => true, 'redirect' => $url]);
                }
                $this->pitchsnap_model->update_site($site->id, ['invoice_id' => null]);
                $site->invoice_id = null;
            }
            if (!$existing_agreement) {
                $text         = get_option('pitchsnap_agreement_text') ?: $this->_default_agreement_text();
                $agreement_id = $this->pitchsnap_model->create_agreement([
                    'site_id'           => (int) $site->id,
                    'client_id'         => (int) $site->client_id,
                    'agreement_version' => $version,
                    'agreement_hash'    => hash('sha256', $text),
                    'accepted_at'       => date('Y-m-d H:i:s'),
                    'ip_address'        => $this->input->ip_address(),
                ]);
                if (!$agreement_id) {
                    return $this->_json(['success' => false, 'error' => 'Could not record agreement acceptance. Please try again.'], 500);
                }
                log_activity('PitchSnap: Agreement accepted [Site ID: ' . $site->id . ', Client ID: ' . $site->client_id . ', Version: ' . $version . ']');
            }
            if (!$site->invoice_id) {
                $existing_inv = $this->_find_existing_pitchsnap_invoice((int) $site->client_id);
                if ($existing_inv) {
                    $invoice_id = (int) $existing_inv->id;
                    $this->_log('accept_agreement', 'Reusing existing PitchSnap invoice', ['invoice_id' => $invoice_id]);
                } else {
                    $invoice_id = $this->_create_invoice((int) $site->client_id, $version);
                    $this->_log('accept_agreement', 'Invoice creation result', [
                        'client_id'  => $site->client_id,
                        'invoice_id' => $invoice_id,
                    ]);
                }
                if (!$invoice_id) {
                    return $this->_json(['success' => false, 'error' => 'Agreement accepted but invoice could not be created. Please contact support.'], 500);
                }
                $this->pitchsnap_model->update_site($site->id, ['invoice_id' => $invoice_id]);
                $site->invoice_id = $invoice_id;
            }
            $url = $this->_create_stripe_checkout_url((int) $site->invoice_id);
            $this->_log('accept_agreement', 'Stripe checkout URL result', [
                'invoice_id' => $site->invoice_id,
                'url'        => $url,
            ]);
            return $this->_json(['success' => true, 'redirect' => $url]);
        }
        } catch (\Throwable $e) {
            return $this->_json(['success' => false, 'error' => 'accept_agreement: ' . $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------------
    // GET /pitchsnap/track_view/{token}
    // -----------------------------------------------------------------------

    public function track_view($token = '')
    {
        $token = trim($token);
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->output->set_status_header(204)->set_output('');
            return;
        }

        if ($this->input->get('ps_admin')) {
            $this->output->set_status_header(204)->set_output('');
            return;
        }

        $this->_load_model();
        $website = $this->pitchsnap_model->get_by_token($token);
        if (!$website) {
            $this->output->set_status_header(204)->set_output('');
            return;
        }

        if (!function_exists('pitchsnap_detect_device_type')) {
            require_once FCPATH . 'modules/pitchsnap/pitchsnap.php';
        }
        $device_type = pitchsnap_detect_device_type($_SERVER['HTTP_USER_AGENT'] ?? '');
        $this->pitchsnap_model->record_view((int) $website->id, $device_type);

        $this->output->set_status_header(204)->set_output('');
    }

    // -----------------------------------------------------------------------
    // GET /pitchsnap/subscription_complete/{site_token}
    // -----------------------------------------------------------------------

    public function subscription_complete($site_token = '')
    {
        $site_token = trim($site_token);
        if (!$site_token || !preg_match('/^[a-f0-9]{64}$/', $site_token)) {
            show_404();
            return;
        }
        $session_id = trim($this->input->get('session_id', true) ?? '');
        if ($session_id) {
            try {
                if (!class_exists('\Stripe\Stripe')) {
                    require_once APPPATH . 'vendor/autoload.php';
                }
                $this->load->library('encryption');
                $secret_key = trim($this->encryption->decrypt(get_option('paymentmethod_stripe_api_secret_key')));
                if ($secret_key) {
                    \Stripe\Stripe::setApiKey($secret_key);
                    $session = \Stripe\Checkout\Session::retrieve([
                        'id'     => $session_id,
                        'expand' => ['subscription'],
                    ]);
                    if ($session && !empty($session->subscription)) {
                        $stripe_sub = $session->subscription;
                        $sub_hash   = $stripe_sub->metadata['pcrm-subscription-hash'] ?? null;
                        if ($sub_hash) {
                            $db_sub = $this->db
                                ->where('hash', $sub_hash)
                                ->get(db_prefix() . 'subscriptions')
                                ->row();
                            if ($db_sub) {
                                $this->db->where('id', (int) $db_sub->id)->update(db_prefix() . 'subscriptions', [
                                    'stripe_subscription_id' => $stripe_sub->id,
                                    'status'                 => $stripe_sub->status === 'active' ? 'Active' : ucfirst($stripe_sub->status),
                                    'date_subscribed'        => date('Y-m-d H:i:s'),
                                    'next_billing_cycle'     => !empty($stripe_sub->current_period_end) ? (int) $stripe_sub->current_period_end : null,
                                ]);
                                $ps_site = $this->db->where('subscription_id', (int) $db_sub->id)->get(db_prefix() . 'pitchsnap_sites')->row();
                                if ($ps_site) {
                                    $this->db->where('id', (int) $ps_site->id)->update(db_prefix() . 'pitchsnap_sites', ['status' => 'subscriber']);
                                    $this->_trigger_onboarding((int) $ps_site->id, (int) $db_sub->clientid);
                                }
                                if (!empty($stripe_sub->customer) && !empty($db_sub->clientid)) {
                                    $client = $this->db->select('stripe_id')->where('userid', (int) $db_sub->clientid)->get(db_prefix() . 'clients')->row();
                                    if ($client && !$client->stripe_id) {
                                        $this->db->where('userid', (int) $db_sub->clientid)->update(db_prefix() . 'clients', ['stripe_id' => $stripe_sub->customer]);
                                    }
                                }
                                log_activity('PitchSnap: Subscription activated [Sub ID: ' . $db_sub->id . ', Stripe: ' . $stripe_sub->id . ']');
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                log_activity('PitchSnap: subscription_complete error: ' . $e->getMessage());
            }
        }
        ob_start();
        include FCPATH . 'modules/pitchsnap/views/subscription_success.php';
        $html = ob_get_clean();
        $this->output
            ->set_content_type('text/html')
            ->set_header('X-Robots-Tag: noindex, nofollow')
            ->set_output($html);
    }

    public function payment_complete($invoice_id = 0, $invoice_hash = '')
    {
        $invoice_id   = (int) $invoice_id;
        $invoice_hash = trim((string) $invoice_hash);

        if (!$invoice_id || !$invoice_hash) {
            show_404();
            return;
        }

        $invoice = $this->db
            ->select('id, hash, total, currency, clientid')
            ->where('id', $invoice_id)
            ->where('hash', $invoice_hash)
            ->get(db_prefix() . 'invoices')
            ->row();

        if (!$invoice) {
            show_404();
            return;
        }

        $session_id = trim($this->input->get('session_id', true) ?? '');

        if ($session_id) {
            try {
                if (!class_exists('\Stripe\Stripe')) {
                    require_once APPPATH . 'vendor/autoload.php';
                }
                $this->load->library('encryption');
                $secret_key = trim($this->encryption->decrypt(get_option('paymentmethod_stripe_api_secret_key')));
                if ($secret_key) {
                    \Stripe\Stripe::setApiKey($secret_key);
                    $session = \Stripe\Checkout\Session::retrieve($session_id);

                    if ($session && $session->payment_status === 'paid' && $session->payment_intent) {
                        $pi_id = is_string($session->payment_intent)
                            ? $session->payment_intent
                            : $session->payment_intent->id;

                        $this->load->model('payments_model');

                        if (!$this->payments_model->transaction_exists($pi_id, $invoice_id)) {
                            $cur    = $this->db->select('name')->where('id', (int) $invoice->currency)->get(db_prefix() . 'currencies')->row();
                            $is_jpy = $cur && strcasecmp($cur->name, 'JPY') === 0;
                            $amount = $is_jpy ? (int) $session->amount_total : round($session->amount_total / 100, 2);

                            $this->payments_model->add([
                                'invoiceid'     => $invoice_id,
                                'amount'        => $amount,
                                'paymentmode'   => 'stripe',
                                'transactionid' => $pi_id,
                            ]);

                            $this->_log('stripe_checkout', 'Payment recorded via payment_complete', [
                                'invoice_id'     => $invoice_id,
                                'payment_intent' => $pi_id,
                                'amount'         => $amount,
                            ]);
                            log_activity('PitchSnap: Invoice #' . $invoice_id . ' payment recorded [PI: ' . $pi_id . ']');

                            // Trigger automatic onboarding link + email for one-time payment
                            $ps_site_ot = $this->db->select('id')->where('invoice_id', $invoice_id)
                                ->get(db_prefix() . 'pitchsnap_sites')->row();
                            if ($ps_site_ot) {
                                $this->_trigger_onboarding((int) $ps_site_ot->id, (int) $invoice->clientid);
                            }
                        }
                    } else {
                        $this->_log('stripe_checkout', 'payment_complete: session not paid or missing payment_intent', [
                            'invoice_id'     => $invoice_id,
                            'session_id'     => $session_id,
                            'payment_status' => $session->payment_status ?? null,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $this->_log('stripe_checkout', 'payment_complete error: ' . $e->getMessage(), [
                    'invoice_id' => $invoice_id,
                    'session_id' => $session_id,
                ]);
                log_activity('PitchSnap: payment_complete error: ' . $e->getMessage());
            }
        }

        set_alert('success', _l('online_payment_recorded_success'));
        redirect(site_url('invoice/' . $invoice_id . '/' . $invoice_hash));
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function _load_model()
    {
        if (!class_exists('Pitchsnap_model')) {
            require_once FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php';
            $this->pitchsnap_model = new Pitchsnap_model();
        }
    }

    private function _log($context, $message, $data = null)
    {
        if (!get_option('pitchsnap_logging_enabled')) {
            return;
        }
        $category = $this->_log_category($context);
        if ($category !== null && !get_option('pitchsnap_log_' . $category)) {
            return;
        }
        try {
            $this->db->insert(db_prefix() . 'pitchsnap_logs', [
                'context'    => substr((string) $context, 0, 50),
                'message'    => $message,
                'data_json'  => $data !== null ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // intentional no-op
        }
    }

    private function _log_category($context)
    {
        static $map = [
            'stripe_checkout'     => 'stripe',
            'stripe_sub'          => 'stripe',
            'invoice_url'         => 'stripe',
            'create_subscription' => 'stripe',
            'purchase'            => 'sales',
            'accept_agreement'    => 'sales',
        ];
        return $map[$context] ?? null;
    }

    private function _find_existing_pitchsnap_invoice($client_id)
    {
        return $this->db
            ->select('id, hash, total, status')
            ->where('clientid', (int) $client_id)
            ->like('adminnote', 'pitchsnap-invoice', 'none')
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get(db_prefix() . 'invoices')
            ->row();
    }

    private function _create_invoice($client_id, $agreement_version = null)
    {
        $currency    = get_base_currency();
        $currency_id = $currency ? (int) $currency->id : 1;
        $client_note = '';
        if ($agreement_version) {
            $client_note = 'PitchSnap Service Agreement v' . $agreement_version . ' accepted on ' . date('F j, Y') . '. 12-month service agreement at $295/month.';
        }
        $price = number_format((float)(get_option('pitchsnap_price') ?: '295.00'), 2, '.', '');
        $data = [
            'clientid'              => (int) $client_id,
            'number'                => (int) get_option('next_invoice_number'),
            'date'                  => date('Y-m-d'),
            'duedate'               => date('Y-m-d', strtotime('+30 days')),
            'currency'              => $currency_id,
            'subtotal'              => $price,
            'total'                 => $price,
            'status'                => 1,
            'clientnote'            => $client_note,
            'adminnote'             => 'pitchsnap-invoice',
            'billing_street'        => '-',
            'allowed_payment_modes' => ['stripe'],
            'newitems'       => [
                [
                    'description'      => 'Complete Website + Lead Response System',
                    'long_description' => '12-month agreement — Full website redesign with AI-powered lead response system.',
                    'qty'              => '1',
                    'unit'             => '',
                    'taxname'          => [],
                    'rate'             => $price,
                    'order'            => 0,
                ],
            ],
        ];
        return $this->invoices_model->add($data);
    }

    private function _invoice_payment_url($invoice_id)
    {
        $row = $this->db
            ->select('id, hash, status, total')
            ->where('id', (int) $invoice_id)
            ->get(db_prefix() . 'invoices')
            ->row();
        $this->_log('invoice_url', 'Hash lookup for invoice #' . $invoice_id, [
            'invoice_id' => $invoice_id,
            'row_found'  => $row ? true : false,
            'hash'       => $row ? $row->hash : null,
        ]);
        if (!$row || !$row->hash) {
            return null;
        }
        return site_url('invoice/' . $invoice_id . '/' . $row->hash);
    }

    private function _create_stripe_checkout_url($invoice_id)
    {
        $invoice = $this->db
            ->select('id, hash, total, currency, clientid')
            ->where('id', (int) $invoice_id)
            ->get(db_prefix() . 'invoices')
            ->row();
        if (!$invoice || !$invoice->hash) {
            $this->_log('stripe_checkout', 'Invoice not found for checkout', ['invoice_id' => $invoice_id]);
            return null;
        }
        $cur = $this->db->select('name')->where('id', (int) $invoice->currency)->get(db_prefix() . 'currencies')->row();
        $currency_name = $cur ? $cur->name : 'USD';
        if (!class_exists('\Stripe\Stripe')) {
            $autoload = APPPATH . 'vendor/autoload.php';
            if (!file_exists($autoload)) {
                $this->_log('stripe_checkout', 'vendor/autoload.php not found');
                return null;
            }
            require_once $autoload;
        }
        $this->load->library('encryption');
        $encrypted_key = get_option('paymentmethod_stripe_api_secret_key');
        if (!$encrypted_key) {
            $this->_log('stripe_checkout', 'Stripe API key not configured');
            return null;
        }
        $secret_key = trim($this->encryption->decrypt($encrypted_key));
        if (!$secret_key) {
            $this->_log('stripe_checkout', 'Stripe API key decryption failed');
            return null;
        }
        \Stripe\Stripe::setApiKey($secret_key);
        $reference = app_generate_hash();
        $this->db->insert(db_prefix() . 'payment_attempts', [
            'reference'       => $reference,
            'amount'          => (float) $invoice->total,
            'fee'             => 0,
            'invoice_id'      => (int) $invoice->id,
            'payment_gateway' => 'stripe',
        ]);
        $client_row = $this->db->select('stripe_id')->where('userid', (int) $invoice->clientid)->get(db_prefix() . 'clients')->row();
        $stripe_customer_id = ($client_row && $client_row->stripe_id) ? $client_row->stripe_id : null;
        $description  = 'Payment for Invoice ' . format_invoice_number($invoice->id);
        $amount_cents = strcasecmp($currency_name, 'JPY') === 0
            ? intval($invoice->total)
            : intval(round((float) $invoice->total * 100));
        $session_data = [
            'payment_method_types' => ['card'],
            'line_items'           => [
                [
                    'name'     => $description,
                    'amount'   => $amount_cents,
                    'currency' => strtolower($currency_name),
                    'quantity' => 1,
                ],
            ],
            'success_url'         => site_url('pitchsnap/payment_complete/' . $invoice->id . '/' . $invoice->hash . '?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url'          => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
            'payment_intent_data' => [
                'description' => $description,
                'metadata'    => [
                    'ClientId'    => $invoice->clientid,
                    'InvoiceId'   => $invoice->id,
                    'InvoiceHash' => $invoice->hash,
                    'payment_attempt_reference' => $reference,
                ],
                'setup_future_usage' => 'off_session',
            ],
            'payment_method_options' => [
                'card' => ['setup_future_usage' => 'off_session'],
            ],
        ];
        if ($stripe_customer_id) {
            $session_data['customer'] = $stripe_customer_id;
        }
        try {
            $session = \Stripe\Checkout\Session::create($session_data);
            $this->_log('stripe_checkout', 'Session created for invoice #' . $invoice->id, [
                'session_id' => $session->id,
                'url'        => $session->url,
            ]);
            return $session->url;
        } catch (\Throwable $e) {
            $this->_log('stripe_checkout', 'Session creation failed: ' . $e->getMessage(), [
                'invoice_id' => $invoice->id,
            ]);
            return null;
        }
    }

    private function _create_pitchsnap_subscription($client_id, $version)
    {
        $stripe_plan_id = get_option('pitchsnap_stripe_plan_id');
        if (!$stripe_plan_id) {
            $this->_log('create_subscription', 'No Stripe plan ID configured');
            return null;
        }
        $quantity     = max(1, (int)(get_option('pitchsnap_sub_quantity') ?: 1));
        $name         = get_option('pitchsnap_sub_name') ?: 'PitchSnap Monthly Service';
        $description  = get_option('pitchsnap_sub_description') ?: '';
        $include_desc = get_option('pitchsnap_sub_include_desc') === '1' ? 1 : 0;
        $tax1         = get_option('pitchsnap_sub_tax1') ?: null;
        $tax2         = get_option('pitchsnap_sub_tax2') ?: null;
        $currency_id = (int)(get_option('pitchsnap_sub_currency') ?: 0);
        if (!$currency_id) {
            $cur         = get_base_currency();
            $currency_id = $cur ? (int) $cur->id : 1;
        }
        $hash = app_generate_hash();
        $this->db->insert(db_prefix() . 'subscriptions', [
            'name'                => $name,
            'description'         => $description,
            'clientid'            => (int) $client_id,
            'date'                => date('Y-m-d'),
            'currency'            => $currency_id,
            'stripe_plan_id'      => $stripe_plan_id,
            'quantity'            => $quantity,
            'status'              => 'Inactive',
            'description_in_item' => $include_desc,
            'stripe_tax_id'       => $tax1,
            'stripe_tax_id_2'     => $tax2,
            'tax_id'              => 0,
            'tax_id_2'            => 0,
            'hash'                => $hash,
            'created'             => date('Y-m-d H:i:s'),
            'created_from'        => 0,
        ]);
        $id = (int) $this->db->insert_id();
        if (!$id) {
            return null;
        }
        log_activity('PitchSnap: Subscription created [Sub ID: ' . $id . ', Client ID: ' . $client_id . ']');
        return ['id' => $id, 'hash' => $hash];
    }

    private function _create_stripe_subscription_url($sub_id, $sub_hash, $site_token)
    {
        $stripe_plan_id = get_option('pitchsnap_stripe_plan_id');
        if (!$stripe_plan_id) {
            $this->_log('stripe_sub', 'No Stripe plan ID configured');
            return null;
        }
        $quantity = max(1, (int)(get_option('pitchsnap_sub_quantity') ?: 1));
        $tax1     = get_option('pitchsnap_sub_tax1') ?: null;
        $tax2     = get_option('pitchsnap_sub_tax2') ?: null;
        if (!class_exists('\Stripe\Stripe')) {
            $autoload = APPPATH . 'vendor/autoload.php';
            if (!file_exists($autoload)) {
                $this->_log('stripe_sub', 'vendor/autoload.php not found');
                return null;
            }
            require_once $autoload;
        }
        $this->load->library('encryption');
        $encrypted_key = get_option('paymentmethod_stripe_api_secret_key');
        if (!$encrypted_key) {
            $this->_log('stripe_sub', 'Stripe API key not configured');
            return null;
        }
        $secret_key = trim($this->encryption->decrypt($encrypted_key));
        if (!$secret_key) {
            $this->_log('stripe_sub', 'Stripe API key decryption failed');
            return null;
        }
        \Stripe\Stripe::setApiKey($secret_key);
        $sub_row = $this->db->select('clientid')->where('id', (int) $sub_id)->get(db_prefix() . 'subscriptions')->row();
        $stripe_customer_id = null;
        if ($sub_row) {
            $client_row = $this->db->select('stripe_id')->where('userid', (int) $sub_row->clientid)->get(db_prefix() . 'clients')->row();
            $stripe_customer_id = ($client_row && $client_row->stripe_id) ? $client_row->stripe_id : null;
        }
        $session_data = [
            'mode'                 => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'           => [[
                'price'    => $stripe_plan_id,
                'quantity' => $quantity,
            ]],
            'success_url'       => site_url('pitchsnap/subscription_complete/' . $site_token . '?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url'        => site_url('pitchsnap/agreement/' . $site_token),
            'subscription_data' => [
                'metadata' => [
                    'pcrm-subscription-hash' => $sub_hash,
                ],
            ],
        ];
        $tax_rates = array_values(array_filter([$tax1, $tax2]));
        if ($tax_rates) {
            $session_data['subscription_data']['default_tax_rates'] = $tax_rates;
        }
        if ($stripe_customer_id) {
            $session_data['customer'] = $stripe_customer_id;
        }
        try {
            $session = \Stripe\Checkout\Session::create($session_data);
            $this->_log('stripe_sub', 'Subscription session created for sub #' . $sub_id, [
                'session_id' => $session->id,
                'url'        => $session->url,
            ]);
            return $session->url;
        } catch (\Throwable $e) {
            $this->_log('stripe_sub', 'Subscription session failed: ' . $e->getMessage(), [
                'sub_id' => $sub_id,
            ]);
            return null;
        }
    }

    private function _normalize_video_url($url)
    {
        $url = trim($url);
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_\-]+)/', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        if (preg_match('/youtube\.com\/watch\?.*v=([a-zA-Z0-9_\-]+)/', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        if (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_\-]+)/', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        if (preg_match('/player\.vimeo\.com\/video\/(\d+)/', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        return $url;
    }

    private function _default_agreement_text()
    {
        return 'PITCHSNAP SERVICE AGREEMENT' . "\n" . 'Version 1.0' . "\n\n"
            . '[LEGAL NOTICE: This is placeholder agreement text requiring final legal review before production use.]' . "\n\n"
            . 'This Service Agreement is between the business identified in the account ("Customer") and the service provider ("Provider").' . "\n\n"
            . 'SERVICES INCLUDED' . "\n"
            . 'Provider will deliver: professionally redesigned business website, AI-powered lead response system integration, technical hosting and maintenance, and ongoing support.' . "\n\n"
            . 'PRICING AND PAYMENT' . "\n"
            . 'Monthly fee: $295.00 USD. Agreement term: 12 months (minimum). First payment due upon acceptance. Payments are non-refundable unless required by law.' . "\n\n"
            . 'CANCELLATION' . "\n"
            . 'Either party may cancel with 30 days written notice after the initial 12-month term. Early cancellation may result in remaining fees becoming due.' . "\n\n"
            . 'GENERAL' . "\n"
            . 'This Agreement constitutes the entire agreement between the parties. Governed by applicable law.';
    }

    private function _activity_text($quick_reply, $change_text)
    {
        switch ($quick_reply) {
            case 'like':
                return 'Prospect liked the new website.';
            case 'change':
                $msg = 'Prospect requested website changes';
                if ($change_text !== '') {
                    $msg .= ': "' . mb_substr($change_text, 0, 200) . '"';
                }
                return $msg . '.';
            case 'not_for_me':
                return 'Prospect indicated the website was not for them.';
            default:
                return 'Prospect submitted a website response.';
        }
    }

    private function _cors()
    {
        $this->output
            ->set_header('Access-Control-Allow-Origin: *')
            ->set_header('Access-Control-Allow-Methods: POST, OPTIONS')
            ->set_header('Access-Control-Allow-Headers: Content-Type');
    }

    // ── WordPress Connector callback (public, no admin auth) ──────────────────
    // WP plugin POSTs {token, site_url} here after the user enters their token.

    public function wp_pair_callback()
    {
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            $this->_json(['success' => false, 'error' => 'POST required.'], 405);
            return;
        }

        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);

        $token    = isset($body['token'])    ? trim((string) $body['token'])    : '';
        $site_url = isset($body['site_url']) ? trim((string) $body['site_url']) : '';

        if (!$token || !$site_url) {
            $this->_json(['success' => false, 'error' => 'token and site_url are required.'], 400);
            return;
        }

        if (!filter_var($site_url, FILTER_VALIDATE_URL)) {
            $this->_json(['success' => false, 'error' => 'Invalid site_url.'], 400);
            return;
        }

        if (!class_exists('Pitchsnap_model')) {
            require_once FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php';
        }
        if (!isset($this->pitchsnap_model)) {
            $this->pitchsnap_model = new Pitchsnap_model();
        }

        $site = $this->pitchsnap_model->find_site_by_pairing_token($token);
        if (!$site) {
            $this->_json(['success' => false, 'error' => 'Invalid or expired token.'], 403);
            return;
        }

        $this->load->library('encryption');
        $encrypted = $this->encryption->encrypt($token);

        $this->pitchsnap_model->confirm_wp_connection(
            $site->id,
            rtrim($site_url, '/'),
            $encrypted
        );

        log_activity('ClickFuzz Web: WordPress connector connected [Site ID: ' . $site->id . ', URL: ' . $site_url . ']');
        $this->_json(['success' => true, 'message' => 'Connected to ClickFuzz Web.'], 200);
    }

    /**
     * Serve a theme ZIP via a one-time signed token generated by the deploy flow.
     * Token stored in /tmp as cf_td_{token} containing the ZIP path + expiry.
     * Expires after 15 minutes or after first use.
     */
    public function wp_theme_download($token = '')
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) $token));
        if (strlen($token) !== 32) {
            $this->output->set_status_header(404);
            return;
        }

        $meta_file = sys_get_temp_dir() . '/cf_td_' . $token . '.meta';
        if (!is_file($meta_file)) {
            $this->output->set_status_header(404);
            return;
        }

        $meta    = json_decode(file_get_contents($meta_file), true);
        $zip     = $meta['zip'] ?? '';
        $expiry  = $meta['expiry'] ?? 0;

        @unlink($meta_file);

        if (time() > $expiry || !is_file($zip)) {
            $this->output->set_status_header(410);
            return;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="clickfuzz-theme.zip"');
        header('Content-Length: ' . filesize($zip));
        readfile($zip);
        exit;
    }

    public function form_render($form_id = 0)
    {
        $this->_cors();
        if ($this->input->server('REQUEST_METHOD') === 'OPTIONS') {
            $this->output->set_status_header(204)->set_output('');
            return;
        }

        $form_id = (int) $form_id;
        if (!$form_id) {
            return $this->_json(['success' => false, 'error' => 'Invalid form ID.'], 400);
        }

        $this->_load_model();
        $form = $this->pitchsnap_model->get_form($form_id);
        if (!$form) {
            return $this->_json(['success' => false, 'error' => 'Form not found.'], 404);
        }

        $fields   = json_decode($form->fields ?? '[]', true) ?: [];
        $settings = json_decode($form->settings ?? '{}', true) ?: [];
        $submit_label = htmlspecialchars($settings['submit_label'] ?? 'Submit', ENT_QUOTES, 'UTF-8');

        // Standard GHL contact field name map (ghl_field value → semantic HTML name)
        $std_name_map = [
            'first_name' => 'first_name',
            'firstName'  => 'first_name',
            'last_name'  => 'last_name',
            'lastName'   => 'last_name',
            'email'      => 'email',
            'phone'      => 'phone',
        ];

        $html  = '<form class="cf-form" data-form-id="' . $form_id . '" novalidate>';
        $html .= '<input type="text" name="cf_trap" style="display:none!important" tabindex="-1" autocomplete="off">';

        $allowed_types = ['text','email','phone','textarea','number','select','multi_select','date','checkbox'];
        foreach ($fields as $idx => $field) {
            $label     = htmlspecialchars($field['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $type      = in_array($field['type'] ?? '', $allowed_types, true) ? $field['type'] : 'text';
            $required  = !empty($field['required']);
            $req_attr  = $required ? ' required' : '';
            $req_mark  = $required ? ' <span class="cf-required">*</span>' : '';
            $options   = is_array($field['options'] ?? null) ? $field['options'] : [];
            $ghl_key   = trim((string) ($field['ghl_field']    ?? ''));
            $dest_mode = trim((string) ($field['ghl_dest_mode'] ?? ''));

            // Stable HTML name for GHL External Tracking
            if ($dest_mode === 'single' && isset($std_name_map[$ghl_key])) {
                $field_name = $std_name_map[$ghl_key];
            } elseif ($dest_mode === 'single' && $ghl_key !== '') {
                $field_name = $ghl_key;
            } else {
                $field_name = 'cf_field[' . $idx . ']';
            }

            $data_idx   = ' data-cf-idx="' . $idx . '"';
            $data_dest  = ($dest_mode === 'multiple') ? ' data-cf-dest="multiple"' : '';
            $data_label = ($dest_mode === 'multiple') ? ' data-cf-label="' . $label . '"' : '';

            $html .= '<div class="cf-field">';

            if ($type === 'checkbox') {
                $html .= '<label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;">';
                $html .= '<input type="checkbox" name="' . $field_name . '" value="yes"' . $req_attr . $data_idx . $data_dest . $data_label . ' style="width:auto;margin:0;">';
                $html .= ' ' . $label . $req_mark;
                $html .= '</label>';
            } elseif ($type === 'textarea') {
                $html .= '<label>' . $label . $req_mark . '</label>';
                $html .= '<textarea name="' . $field_name . '" rows="4"' . $req_attr . $data_idx . $data_dest . $data_label . '></textarea>';
            } elseif ($type === 'select') {
                $html .= '<label>' . $label . $req_mark . '</label>';
                $html .= '<select name="' . $field_name . '"' . $req_attr . $data_idx . $data_dest . $data_label . '>';
                $html .= '<option value="">— Select —</option>';
                foreach ($options as $opt) {
                    $os = htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8');
                    $html .= '<option value="' . $os . '">' . $os . '</option>';
                }
                $html .= '</select>';
            } elseif ($type === 'multi_select') {
                $html .= '<label>' . $label . $req_mark . '</label>';
                $html .= '<div class="cf-multi-select" data-cf-idx="' . $idx . '"' . $data_dest . $data_label . ' style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">';
                foreach ($options as $opt) {
                    $os = htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8');
                    $html .= '<label style="display:flex;align-items:center;gap:8px;font-weight:normal;cursor:pointer;margin:0;">';
                    $html .= '<input type="checkbox" class="cf-ms-opt" value="' . $os . '" style="width:auto;margin:0;">';
                    $html .= ' ' . $os;
                    $html .= '</label>';
                }
                $html .= '</div>';
            } else {
                $html_type = ($type === 'phone') ? 'tel' : $type;
                $html .= '<label>' . $label . $req_mark . '</label>';
                $html .= '<input type="' . $html_type . '" name="' . $field_name . '"' . $req_attr . $data_idx . $data_dest . $data_label . '>';
            }

            $html .= '</div>';
        }

        // Hidden textarea populated by the document capture listener before GHL reads the form
        $html .= '<textarea name="quote_content" style="display:none!important" aria-hidden="true" tabindex="-1"></textarea>';
        $html .= '<div class="cf-field"><button type="submit" class="cf-submit">' . $submit_label . '</button></div>';
        $html .= '<div class="cf-msg" style="display:none"></div>';
        $html .= '</form>';

        $ghl_tracking_id = (string) (get_option('pitchsnap_ghl_tracking_id_' . (int) $form->site_id) ?: '');

        return $this->_json(['success' => true, 'html' => $html, 'form_id' => $form_id, 'ghl_tracking_id' => $ghl_tracking_id]);
    }

    /**
     * POST pitchsnap/form_submit
     * Validates and routes a form submission to GHL.
     * Body: JSON { form_id, site_token, fields: {ghl_field: value, ...} }
     */
    public function form_submit()
    {
        $this->_cors();
        if ($this->input->server('REQUEST_METHOD') === 'OPTIONS') {
            $this->output->set_status_header(204)->set_output('');
            return;
        }
        if ($this->input->server('REQUEST_METHOD') !== 'POST') {
            return $this->_json(['success' => false, 'error' => 'Method not allowed.'], 405);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->_json(['success' => false, 'error' => 'Invalid request body.'], 400);
        }

        $form_id    = (int)    ($body['form_id']    ?? 0);
        $site_token = trim((string) ($body['site_token'] ?? ''));
        $fields     = is_array($body['fields'] ?? null) ? $body['fields'] : [];

        if (!$form_id) {
            return $this->_json(['success' => false, 'error' => 'Missing form_id.'], 400);
        }

        // Honeypot check
        if (!empty($body['cf_trap'])) {
            return $this->_json(['success' => true]); // silent discard
        }

        $this->_load_model();
        $form = $this->pitchsnap_model->get_form($form_id);
        if (!$form) {
            return $this->_json(['success' => false, 'error' => 'Form not found.'], 404);
        }

        // Validate required fields (indexed by form field position)
        $form_fields = json_decode($form->fields ?? '[]', true) ?: [];
        foreach ($form_fields as $idx => $ff) {
            if (!empty($ff['required'])) {
                $raw = $fields[$idx] ?? ($fields[(string)$idx] ?? null);
                $str = is_array($raw) ? implode('', array_map('strval', $raw)) : trim((string)($raw ?? ''));
                if ($str === '') {
                    $label = $ff['label'] ?? 'Field';
                    return $this->_json(['success' => false, 'error' => $label . ' is required.'], 422);
                }
            }
        }

        // Sanitize field values (keys are field indexes — numeric strings)
        $clean = [];
        foreach ($fields as $k => $v) {
            $idx = (int) $k;
            if (is_array($v)) {
                $clean[$idx] = array_values(array_map(function ($s) { return mb_substr(strip_tags((string) $s), 0, 500); }, $v));
            } else {
                $clean[$idx] = mb_substr(strip_tags((string) $v), 0, 500);
            }
        }

        // Resolve site for GHL lookup
        $site = null;
        if ($site_token !== '') {
            $site = $this->pitchsnap_model->get_site_by_token($site_token);
        }
        if (!$site) {
            $site = $this->pitchsnap_model->get_site_by_id((int) $form->site_id);
        }

        $ghl_contact_id = null;
        $site_id        = $site ? (int) $site->id : (int) $form->site_id;

        // Lead delivery is via GHL External Tracking (client-side). Webhook remains available.
        if ($site) {
            $webhook_url = get_option('pitchsnap_ghl_form_webhook_url');
            if ($webhook_url) {
                $this->_post_webhook($webhook_url, [
                    'form_id'      => $form_id,
                    'form_name'    => $form->name,
                    'site_id'      => $site_id,
                    'fields'       => $clean,
                    'submitted_at' => date('c'),
                ]);
            }
        }

        $this->pitchsnap_model->log_form_submission([
            'form_id'        => $form_id,
            'site_id'        => $site_id,
            'ghl_contact_id' => $ghl_contact_id,
            'submitted_at'   => date('Y-m-d H:i:s'),
        ]);

        $settings        = json_decode($form->settings ?? '{}', true) ?: [];
        $success_message = $settings['success_message'] ?? 'Thank you! We\'ll be in touch soon.';

        return $this->_json(['success' => true, 'message' => $success_message]);
    }

    private function _post_webhook($url, array $data)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function _ob_status_page($message)
    {
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<style>'
            . '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }'
            . 'html, body { background: transparent; }'
            . 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;'
            . '       font-size: 15px; line-height: 1.6; color: #fff; padding: 10px 16px 56px; }'
            . '.wiz-page { max-width: 620px; margin: 0 auto; text-align: center; padding: 48px 16px; }'
            . '.wiz-icon { font-size: 40px; margin-bottom: 16px; color: #e0392b; }'
            . '.wiz-msg  { color: rgba(255,255,255,.75); font-size: 14px; }'
            . '</style>'
            . '</head><body>'
            . '<div class="wiz-page">'
            . '<div class="wiz-icon">&#10003;</div>'
            . '<p class="wiz-msg">' . $msg . '</p>'
            . '</div>'
            . '</body></html>';
    }

    private function _trigger_onboarding($site_id, $client_id)
    {
        $site_id   = (int) $site_id;
        $client_id = (int) $client_id;
        if (!$site_id || !$client_id) { return; }

        // Idempotency: skip if an auto-link was already created for this site
        $site_row = $this->db->select('onboarding_link_id')->where('id', $site_id)
            ->get(db_prefix() . 'pitchsnap_sites')->row();
        if ($site_row && !empty($site_row->onboarding_link_id)) { return; }

        // Configured flow
        $flow_id = (int) get_option('pitchsnap_onboarding_flow_id');
        if (!$flow_id) {
            log_activity('ClickFuzz Web: Onboarding skipped — no flow configured [site_id: ' . $site_id . ']');
            return;
        }
        $this->_load_model();
        $flow = $this->pitchsnap_model->get_flow($flow_id);
        if (!$flow || $flow['status'] !== 'active') {
            log_activity('ClickFuzz Web: Onboarding skipped — flow not active [flow_id: ' . $flow_id . ', site_id: ' . $site_id . ']');
            return;
        }

        // Create onboarding link (uses cryptographically secure token)
        $token = $this->pitchsnap_model->create_onboarding_link($site_id, $flow_id);
        if (!$token) {
            log_activity('ClickFuzz Web: Onboarding link creation failed [site_id: ' . $site_id . ']');
            return;
        }

        // Store link reference on site record (idempotency marker for subsequent calls)
        $link_row = $this->db->select('id')->where('token', $token)
            ->get(db_prefix() . 'pitchsnap_onboarding_links')->row();
        if ($link_row) {
            $this->pitchsnap_model->update_site($site_id, ['onboarding_link_id' => (int) $link_row->id]);
        }

        // Build public onboarding URL
        $ob_page = !empty($flow['page_url']) ? $flow['page_url'] : get_option('pitchsnap_onboarding_page_url');
        $ob_url  = $ob_page
            ? rtrim($ob_page, '/') . '/?token=' . $token
            : base_url('pitchsnap/onboarding_embed') . '?token=' . $token;

        // Resolve primary contact email
        $contact = $this->db->select('email, firstname')
            ->where('userid', $client_id)
            ->where('is_primary', 1)
            ->get(db_prefix() . 'contacts')
            ->row();
        if (!$contact || empty($contact->email)) {
            log_activity('ClickFuzz Web: Onboarding email skipped — no primary contact [client_id: ' . $client_id . ', site_id: ' . $site_id . ']');
            return;
        }

        $this->load->helper('pitchsnap_cron');
        clickfuzz_web_send_mail('pitchsnap-onboarding', $contact->email, [
            '{{contact_firstname}}' => !empty($contact->firstname) ? $contact->firstname : 'there',
            '{{onboarding-link}}'   => $ob_url,
        ]);
    }

    private function _json($data, $status = 200)
    {
        $this->output
            ->set_status_header($status)
            ->set_content_type('application/json')
            ->set_output(json_encode($data));
    }
}
