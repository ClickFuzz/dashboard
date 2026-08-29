<?php
defined('BASEPATH') or exit('No direct script access allowed');

// ---------------------------------------------------------------------------
// Cron — three-phase async-aware processing
// ---------------------------------------------------------------------------

function clickfuzz_web_cron_run()
{
    $CI =& get_instance();

    if (!class_exists('Pitchsnap_model')) {
        require_once FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php';
        $CI->pitchsnap_model = new Pitchsnap_model();
    }

    $lib_dir = FCPATH . 'modules/pitchsnap/libraries/';
    if (!class_exists('Pitchsnap_generator')) {
        require_once $lib_dir . 'Pitchsnap_generator.php';
    }
    if (!class_exists('Pitchsnap_manus')) {
        require_once $lib_dir . 'Pitchsnap_manus.php';
    }
    if (!class_exists('Pitchsnap_anthropic')) {
        require_once $lib_dir . 'Pitchsnap_anthropic.php';
    }

    $CI->pitchsnap_model->rescue_stuck();

    clickfuzz_web_notify_pending_admin_approvals($CI);
    clickfuzz_web_check_subscription_lifecycle($CI);

    // -----------------------------------------------------------------------
    // Phase 1: Start new generations (pending_generation → generating)
    // -----------------------------------------------------------------------

    $batch = $CI->pitchsnap_model->get_pending_generation(5);

    foreach ($batch as $website) {
        if (!$CI->pitchsnap_model->claim_for_generation($website->id)) {
            continue;
        }

        try {
            // provider column pre-set means a forced provider (e.g. Anthropic retry)
            $provider_name = !empty($website->provider)
                ? $website->provider
                : (get_option('pitchsnap_primary_provider') ?: 'manus');

            $generator = new Pitchsnap_generator($provider_name);

            $lead = null;
            if ($website->lead_id) {
                if (!class_exists('Leads_model')) {
                    $CI->load->model('leads_model');
                }
                $lead = $CI->leads_model->get($website->lead_id);
            }

            // Normalize source package — select guardrail profile by provider.
            $source_url     = ($lead && !empty($lead->website)) ? $lead->website : ($website->original_url ?? '');
            $guardrails     = clickfuzz_web_guardrail_profile($provider_name);
            $source_content = !empty($source_url) ? clickfuzz_web_fetch_source($source_url, $guardrails) : '';

            // Read Vertical from the lead's custom field; fall back to redesign's stored value
            $vertical = $website->vertical ?? 'Local Business';
            if ($lead) {
                $cf_row = $CI->db->select('cv.value')
                    ->from(db_prefix() . 'customfieldsvalues cv')
                    ->join(db_prefix() . 'customfields cf', 'cf.id = cv.fieldid')
                    ->where('cf.fieldto', 'leads')
                    ->where('cf.name', 'Vertical')
                    ->where('cv.relid', (int) $website->lead_id)
                    ->get()->row();
                if ($cf_row && $cf_row->value !== '') {
                    $vertical = $cf_row->value;
                }
            }

            $prompt_data = [
                'business_name'       => $lead ? ($lead->company ?: $lead->name) : '',
                'email'               => $lead ? $lead->email        : '',
                'phone'               => $lead ? $lead->phonenumber  : '',
                'website_url'         => $source_url,
                'role'                => $lead ? ($lead->position ?? '') : ($website->intake_role ?? ''),
                'company_size'        => $website->intake_company_size ?? '',
                'desired_improvement' => $website->intake_improvement  ?? '',
                'vertical'            => $vertical,
                'preview_token'       => $website->preview_token       ?? '',
                'source_content'      => $source_content,
            ];

            $prompt_key = ($provider_name === 'manus')
                ? 'pitchsnap_manus_prompt'
                : 'pitchsnap_generation_prompt';

            $rendered = clickfuzz_web_render_prompt(get_option($prompt_key), $prompt_data);
            $CI->pitchsnap_model->save_generation_prompt($website->id, $rendered);

            if ($generator->is_async()) {
                // ----- Manus async path -----
                $result = $generator->start($rendered);

                if ($result['success']) {
                    $CI->pitchsnap_model->save_manus_task_started($website->id, $result['task_id'], 'manus');
                    log_activity('ClickFuzz Web: Manus task started [Website ID: ' . $website->id . ', Task: ' . $result['task_id'] . ']');
                } else {
                    $error    = $result['error'];
                    $fallback = get_option('pitchsnap_fallback_provider') ?: 'none';

                    if ($generator->is_quota_error($error) && $fallback === 'anthropic') {
                        // Auto-fallback to Anthropic on quota exhaustion
                        // Fetch source content with full Anthropic guardrail profile
                        $fb_source  = clickfuzz_web_fetch_source($website->original_url ?? '', clickfuzz_web_guardrail_profile('anthropic'));
                        $fb_data    = array_merge($prompt_data, ['source_content' => $fb_source]);
                        $fb_rendered = clickfuzz_web_render_prompt(
                            get_option('pitchsnap_generation_prompt'),
                            $fb_data
                        );
                        $CI->pitchsnap_model->save_generation_prompt($website->id, $fb_rendered);
                        $fb_gen    = new Pitchsnap_generator('anthropic');
                        $fb_result = $fb_gen->generate($fb_rendered);

                        if ($fb_result['success']) {
                            $html = $fb_result['result'];
                            if (preg_match('/^```(?:html)?\s*([\s\S]+?)\s*```$/i', trim($html), $m)) {
                                $html = $m[1];
                            }
                            $html = preg_replace_callback(
                                '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i',
                                function ($m) {
                                    return (strpos($m[1], 'clickfuzz.com/dashboard/pitchsnap/runtime.js') !== false)
                                        ? $m[0] : '';
                                },
                                $html
                            );
                            // Strip all external scripts; re-inject canonical ClickFuzz Web widget with site token
                            $_ps_color = '';
                            if (preg_match('/<script[^>]+pitchsnap\/runtime\.js[^>]*\bdata-primary-color=["\']([^"\']{1,20})["\'][^>]*>/i', $html, $_m)) {
                                $_c = trim($_m[1]);
                                if (preg_match('/^#[0-9A-Fa-f]{3}$|^#[0-9A-Fa-f]{6}$/', $_c)) { $_ps_color = $_c; }
                            }
                            $html     = preg_replace('/<script[^>]+\bsrc=[^>]*>\s*<\/script>/i', '', $html);
                            $ps_site  = clickfuzz_web_ensure_site($website->id, $website->lead_id ?? null);
                            $widget   = clickfuzz_web_runtime_script_tag($website->preview_token, $ps_site ? $ps_site->site_token : '', $_ps_color);
                            if (stripos($html, '</body>') !== false) {
                                $html = str_ireplace('</body>', $widget . "\n</body>", $html);
                            } else {
                                $html .= "\n" . $widget;
                            }
                            $validation_error = clickfuzz_web_validate_html($html);
                            if ($validation_error) {
                                $CI->pitchsnap_model->mark_generation_failed($website->id, 'Anthropic fallback validation: ' . $validation_error);
                            } else {
                                $deploy = clickfuzz_web_deploy_preview($website->preview_token, $html);
                                if ($deploy['success']) {
                                    $CI->pitchsnap_model->mark_generation_success($website->id, $fb_result, 'anthropic', $deploy['url']);
                                    log_activity('ClickFuzz Web: Anthropic fallback succeeded [Website ID: ' . $website->id . ']');
                                } else {
                                    $CI->pitchsnap_model->mark_generation_failed($website->id, 'Manus quota; Anthropic deploy failed: ' . $deploy['error']);
                                }
                            }
                        } else {
                            $CI->pitchsnap_model->mark_generation_failed($website->id, 'Manus quota; Anthropic fallback failed: ' . $fb_result['error']);
                            log_activity('ClickFuzz Web: Both providers failed [Website ID: ' . $website->id . ']');
                        }
                    } else {
                        $CI->pitchsnap_model->mark_generation_failed($website->id, 'Manus: ' . $error);
                        log_activity('ClickFuzz Web: Manus start failed [Website ID: ' . $website->id . '] ' . $error);
                    }
                }
            } else {
                // ----- Anthropic sync path -----
                $result = $generator->generate($rendered);

                if ($result['success']) {
                    $html = $result['result'];
                    // Strip markdown fences if Claude wrapped the output (defensive)
                    if (preg_match('/^```(?:html)?\s*([\s\S]+?)\s*```$/i', trim($html), $m)) {
                        $html = $m[1];
                    }
                    // Strip any external <script src> tags that aren't the ClickFuzz Web runtime
                    $html = preg_replace_callback(
                        '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i',
                        function ($m) {
                            return (strpos($m[1], 'clickfuzz.com/dashboard/pitchsnap/runtime.js') !== false)
                                ? $m[0] : '';
                        },
                        $html
                    );

                    // Strip all external scripts; re-inject canonical ClickFuzz Web widget with site token
                    $_ps_color = '';
                    if (preg_match('/<script[^>]+pitchsnap\/runtime\.js[^>]*\bdata-primary-color=["\']([^"\']{1,20})["\'][^>]*>/i', $html, $_m)) {
                        $_c = trim($_m[1]);
                        if (preg_match('/^#[0-9A-Fa-f]{3}$|^#[0-9A-Fa-f]{6}$/', $_c)) { $_ps_color = $_c; }
                    }
                    $html     = preg_replace('/<script[^>]+\bsrc=[^>]*>\s*<\/script>/i', '', $html);
                    $ps_site  = clickfuzz_web_ensure_site($website->id, $website->lead_id ?? null);
                    $widget   = clickfuzz_web_runtime_script_tag($website->preview_token, $ps_site ? $ps_site->site_token : '', $_ps_color);
                    if (stripos($html, '</body>') !== false) {
                        $html = str_ireplace('</body>', $widget . "\n</body>", $html);
                    } else {
                        $html .= "\n" . $widget;
                    }

                    $validation_error = clickfuzz_web_validate_html($html);
                    if ($validation_error) {
                        $CI->pitchsnap_model->mark_generation_failed($website->id, 'Validation: ' . $validation_error);
                        log_activity('ClickFuzz Web: HTML validation failed [Website ID: ' . $website->id . '] ' . $validation_error);
                    } else {
                        $deploy = clickfuzz_web_deploy_preview($website->preview_token, $html);
                        if ($deploy['success']) {
                            $result['result'] = $html; // store cleaned HTML
                            $CI->pitchsnap_model->mark_generation_success($website->id, $result, 'anthropic', $deploy['url']);
                            log_activity('ClickFuzz Web: Anthropic succeeded [Website ID: ' . $website->id . ', URL: ' . $deploy['url'] . ']');
                        } else {
                            $CI->pitchsnap_model->mark_generation_failed($website->id, 'Deploy failed: ' . $deploy['error']);
                            log_activity('ClickFuzz Web: Deploy failed [Website ID: ' . $website->id . '] ' . $deploy['error']);
                        }
                    }
                } else {
                    $CI->pitchsnap_model->mark_generation_failed($website->id, $result['error']);
                    log_activity('ClickFuzz Web: Anthropic failed [Website ID: ' . $website->id . '] ' . $result['error']);
                }
            }
        } catch (Exception $e) {
            $CI->pitchsnap_model->mark_generation_failed($website->id, $e->getMessage());
            log_activity('ClickFuzz Web: Cron exception [Website ID: ' . $website->id . '] ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Phase 2: Poll Manus tasks that are actively generating
    //          stopped → publish; error → fail; running/waiting → leave
    // -----------------------------------------------------------------------

    $generating = $CI->pitchsnap_model->get_generating_manus(5);

    foreach ($generating as $website) {
        try {
            $generator = new Pitchsnap_generator('manus');
            $poll      = $generator->poll_task($website->provider_project_id);

            if ($poll['status'] === 'stopped') {
                $pub = $generator->publish($website->provider_project_id);
                if ($pub['success']) {
                    $CI->pitchsnap_model->save_manus_publish_started($website->id, $pub['website_id']);
                    log_activity('ClickFuzz Web: Manus publish started [Website ID: ' . $website->id . ', Website: ' . $pub['website_id'] . ']');
                } else {
                    $CI->pitchsnap_model->mark_generation_failed($website->id, 'Website publish failed: ' . $pub['error']);
                    log_activity('ClickFuzz Web: Manus publish failed [Website ID: ' . $website->id . '] ' . $pub['error']);
                }
            } elseif ($poll['status'] === 'error') {
                $CI->pitchsnap_model->mark_generation_failed($website->id, 'Manus task error: ' . ($poll['error'] ?? 'unknown'));
                log_activity('ClickFuzz Web: Manus task error [Website ID: ' . $website->id . ']');
            }
            // 'running' or 'waiting': leave in generating state
        } catch (Exception $e) {
            $CI->pitchsnap_model->mark_generation_failed($website->id, $e->getMessage());
            log_activity('ClickFuzz Web: Poll exception [Website ID: ' . $website->id . '] ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Phase 3: Poll Manus websites that are deploying
    //          published → store URL, review_required; failed → fail
    // -----------------------------------------------------------------------

    $publishing = $CI->pitchsnap_model->get_publishing_manus(5);

    foreach ($publishing as $website) {
        try {
            $generator = new Pitchsnap_generator('manus');
            $status    = $generator->poll_publish($website->provider_website_id);

            if ($status['status'] === 'published') {
                $CI->pitchsnap_model->mark_manus_complete($website->id, $status['url']);
                log_activity('ClickFuzz Web: Manus website live [Website ID: ' . $website->id . ', URL: ' . $status['url'] . ']');
            } elseif ($status['status'] === 'failed' || $status['status'] === 'error') {
                $CI->pitchsnap_model->mark_generation_failed($website->id, 'Website deployment failed: ' . ($status['error'] ?? ''));
                log_activity('ClickFuzz Web: Manus deployment failed [Website ID: ' . $website->id . ']');
            }
            // 'publishing': leave as-is, poll next cron
        } catch (Exception $e) {
            $CI->pitchsnap_model->mark_generation_failed($website->id, $e->getMessage());
            log_activity('ClickFuzz Web: Publish poll exception [Website ID: ' . $website->id . '] ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Phase 4: Generate internal pages queued for AI generation
    //          Pages with generation_status='generating' → Anthropic sync
    // -----------------------------------------------------------------------

    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_page_generation_helper.php';

    $pages_to_generate = $CI->pitchsnap_model->get_pages_for_generation(5);

    foreach ($pages_to_generate as $page) {
        try {
            clickfuzz_web_generate_page($page);
        } catch (Exception $e) {
            $CI->pitchsnap_model->mark_page_generation_failed($page->id, $e->getMessage());
            log_activity('ClickFuzz Web: Page generation exception [Page #' . $page->id . '] ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Phase 5: Poll pending Cloudflare Custom Hostname statuses
    //          connected → mark Connected and stop polling
    //          failed    → mark Failed and stop polling
    //          pending   → leave for next cron run
    // -----------------------------------------------------------------------

    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_cloudflare_helper.php';

    $pending_cf = $CI->pitchsnap_model->get_pending_cf_hostnames(20);
    foreach ($pending_cf as $domain) {
        try {
            $cf_result = clickfuzz_web_cf_check_hostname($domain->cf_hostname_id);
            if ($cf_result['status'] !== 'pending') {
                $CI->pitchsnap_model->update_cf_status($domain->id, $cf_result['status']);
                log_activity('ClickFuzz Web: CF hostname ' . $cf_result['status'] . ' [Domain ID: ' . $domain->id . ', Hostname: ' . $domain->hostname . ']');
            }
        } catch (Exception $e) {
            log_activity('ClickFuzz Web: CF poll exception [Domain ID: ' . $domain->id . '] ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
// Email template registration
// ---------------------------------------------------------------------------

function clickfuzz_web_register_email_templates()
{
    $templates = [
        [
            'pitchsnap-website-needs-approval',
            'ClickFuzz Web — Website Needs Approval',
            'A new website is ready for your design review.<br><br><strong>Company:</strong> {company}<br><br><a href="{admin_review_link}">Review Website</a>',
        ],
        [
            'pitchsnap-website-ready',
            'ClickFuzz Web — Your New Website Is Ready',
            'Hi {company},<br><br>Your new website is ready for review!<br><br><a href="{website_review_url}">View Your Website</a><br><br>Let us know what you think.',
        ],
        [
            'pitchsnap-subscription-started',
            'ClickFuzz Web — Welcome! Your Subscription Is Active',
            'Hi {company},<br><br>Your ClickFuzz Web subscription is now active.',
        ],
        [
            'pitchsnap-subscription-cancelled',
            'ClickFuzz Web — Your Subscription Has Been Cancelled',
            'Hi {company},<br><br>Your ClickFuzz Web subscription has been cancelled.',
        ],
        [
            'pitchsnap-payment-failed',
            'ClickFuzz Web — Payment Failed',
            'Hi {company},<br><br>We were unable to process your ClickFuzz Web payment. Please update your payment method.',
        ],
        [
            'pitchsnap-payment-recovered',
            'ClickFuzz Web — Payment Recovered',
            'Hi {company},<br><br>Your ClickFuzz Web payment has been successfully processed.',
        ],
        [
            'pitchsnap-website-published',
            'ClickFuzz Web — Your Website Is Now Live',
            'Hi {company},<br><br>Your website is now live at: <a href="{production_website_url}">{production_website_url}</a>',
        ],
    ];

    $CI =& get_instance();
    $t  = db_prefix() . 'emailtemplates';

    foreach ($templates as [$slug, $subject, $message]) {
        $exists = $CI->db->where('slug', $slug)->count_all_results($t);
        if (!$exists) {
            $CI->db->insert($t, [
                'slug'      => $slug,
                'language'  => 'english',
                'name'      => $subject,
                'subject'   => $subject,
                'message'   => $message,
                'type'      => 'customer',
                'fromname'  => '',
                'fromemail' => '',
                'active'    => 1,
                'plaintext' => 0,
            ]);
        }
    }
}

// ---------------------------------------------------------------------------
// Direct mail sender
// ---------------------------------------------------------------------------

function clickfuzz_web_send_mail($slug, $to_email, $merge_fields = [])
{
    $CI =& get_instance();

    $template = $CI->db->where('slug', $slug)->get(db_prefix() . 'emailtemplates')->row();
    if (!$template || empty($template->message)) {
        log_activity('ClickFuzz Web: Email template not found [slug: ' . $slug . ']');
        return false;
    }

    $subject = $template->subject ?? $slug;
    $body    = $template->message;

    foreach ($merge_fields as $key => $val) {
        $subject = str_replace($key, $val, $subject);
        $body    = str_replace($key, $val, $body);
    }

    $CI->load->library('email');
    $CI->email->initialize(['mailtype' => 'html', 'charset' => 'utf-8']);
    $CI->email->from(get_option('smtp_email') ?: get_option('email'), get_option('companyname'));
    $CI->email->to($to_email);
    $CI->email->subject($subject);
    $CI->email->message($body);

    $sent = $CI->email->send(false);
    if (!$sent) {
        log_activity('ClickFuzz Web: Email send failed [slug: ' . $slug . ', to: ' . $to_email . ']');
    }
    return $sent;
}

// ---------------------------------------------------------------------------
// Helper: primary contact email for a Perfex client
// ---------------------------------------------------------------------------

function clickfuzz_web_get_client_email($client_id)
{
    $CI =& get_instance();
    $row = $CI->db
        ->select('email')
        ->where('userid', (int) $client_id)
        ->where('is_primary', 1)
        ->limit(1)
        ->get(db_prefix() . 'contacts')
        ->row();
    return $row ? $row->email : null;
}

// ---------------------------------------------------------------------------
// Cron: notify web design admin of websites pending approval
// ---------------------------------------------------------------------------

function clickfuzz_web_notify_pending_admin_approvals($CI)
{
    $admin_staff_id = (int) get_option('pitchsnap_web_design_admin');
    if (!$admin_staff_id) {
        return;
    }

    $admin_row = $CI->db->select('email, firstname, lastname')
        ->where('staffid', $admin_staff_id)
        ->get(db_prefix() . 'staff')
        ->row();
    if (!$admin_row || empty($admin_row->email)) {
        return;
    }

    $pending = $CI->pitchsnap_model->get_websites_pending_admin_notification();
    foreach ($pending as $website) {
        $company = '';
        if (!empty($website->lead_id)) {
            $lead = $CI->db->select('company, name')->where('id', (int) $website->lead_id)->get(db_prefix() . 'leads')->row();
            $company = $lead ? ($lead->company ?: $lead->name) : '';
        }

        clickfuzz_web_send_mail('pitchsnap-website-needs-approval', $admin_row->email, [
            '{company}'           => $company,
            '{admin_review_link}' => admin_url('pitchsnap/detail/' . $website->id),
        ]);

        $CI->pitchsnap_model->mark_admin_notified($website->id);
        log_activity('ClickFuzz Web: Admin approval notification sent [Website ID: ' . $website->id . ']');
    }
}

// ---------------------------------------------------------------------------
// Cron: subscription lifecycle emails
// ---------------------------------------------------------------------------

function clickfuzz_web_check_subscription_lifecycle($CI)
{
    $sites = $CI->pitchsnap_model->get_sites_for_subscription_check();

    foreach ($sites as $site) {
        if (empty($site->subscription_id)) continue;

        $sub = $CI->db->select('id, status, clientid')
            ->where('id', (int) $site->subscription_id)
            ->get(db_prefix() . 'subscriptions')
            ->row();
        if (!$sub) continue;

        $perfex_status = strtolower($sub->status ?? '');
        $last_status   = strtolower($site->sub_email_last_status ?? '');
        if ($perfex_status === $last_status) continue;

        $client_email = clickfuzz_web_get_client_email((int) $sub->clientid);
        if (!$client_email) {
            $CI->pitchsnap_model->update_site($site->id, ['sub_email_last_status' => $perfex_status]);
            continue;
        }

        $client_row = $CI->db->select('company')->where('userid', (int) $sub->clientid)->get(db_prefix() . 'clients')->row();
        $company = $client_row ? $client_row->company : '';

        $slug = null;
        if ($perfex_status === 'active' && $last_status !== 'active') {
            $slug = 'pitchsnap-subscription-started';
        } elseif (in_array($perfex_status, ['canceled', 'cancelled'])) {
            $slug = 'pitchsnap-subscription-cancelled';
        } elseif ($perfex_status === 'past_due' && $last_status !== 'past_due') {
            $slug = 'pitchsnap-payment-failed';
        } elseif ($perfex_status === 'active' && in_array($last_status, ['past_due', 'unpaid'])) {
            $slug = 'pitchsnap-payment-recovered';
        }

        if ($slug) {
            clickfuzz_web_send_mail($slug, $client_email, ['{company}' => $company]);
            log_activity('ClickFuzz Web: Lifecycle email sent [Site ID: ' . $site->id . ', slug: ' . $slug . ']');
        }

        $CI->pitchsnap_model->update_site($site->id, ['sub_email_last_status' => $perfex_status]);
    }
}

// ---------------------------------------------------------------------------
// Helper: detect device type from user agent
// ---------------------------------------------------------------------------

function clickfuzz_web_detect_device_type($ua = '')
{
    $ua = strtolower($ua ?: ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (preg_match('/ipad|tablet|kindle|playbook|silk|(android(?!.*mobile))/i', $ua)) {
        return 'tablet';
    }
    if (preg_match('/mobile|iphone|ipod|android|blackberry|iemobile|opera mini/i', $ua)) {
        return 'mobile';
    }
    if ($ua) {
        return 'desktop';
    }
    return 'unknown';
}

// ---------------------------------------------------------------------------
// Email templates: render dedicated ClickFuzz Web section on the admin page
// ---------------------------------------------------------------------------

function clickfuzz_web_email_templates_section()
{
    $CI =& get_instance();
    $templates = $CI->db
        ->where('slug LIKE', 'pitchsnap%')
        ->where('language', 'english')
        ->order_by('emailtemplateid', 'ASC')
        ->get(db_prefix() . 'emailtemplates')
        ->result_array();

    if (empty($templates)) {
        return;
    }

    $can_edit = staff_can('edit', 'email_templates');
    ?>
    <div class="col-md-12">
        <h4 class="tw-font-semibold email-template-heading">ClickFuzz Web</h4>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr><th><span class="tw-font-semibold"><?php echo _l('email_templates_table_heading_name'); ?></span></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $t) { ?>
                    <tr>
                        <td class="<?php echo $t['active'] == 0 ? 'text-throught' : ''; ?>">
                            <a href="<?php echo admin_url('emails/email_template/' . $t['emailtemplateid']); ?>"><?php echo e($t['name']); ?></a>
                            <?php if ($can_edit) { ?>
                            <a href="<?php echo admin_url('emails/' . ($t['active'] == '1' ? 'disable/' : 'enable/') . $t['emailtemplateid']); ?>"
                                class="pull-right"><small><?php echo _l($t['active'] == 1 ? 'disable' : 'enable'); ?></small></a>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

