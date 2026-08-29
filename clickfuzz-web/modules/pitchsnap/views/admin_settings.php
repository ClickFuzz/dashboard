<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mb-4">ClickFuzz Web Settings</h4>

                        <ul class="nav nav-tabs mbot20" id="settings-tabs">
                            <li class="<?php echo $active_tab !== 'logs' ? 'active' : ''; ?>">
                                <a href="#tab-general" data-toggle="tab">General</a>
                            </li>
                            <li class="<?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
                                <a href="#tab-logs" data-toggle="tab">Logs</a>
                            </li>
                        </ul>

                        <div class="tab-content">

                        <!-- ══════════════════════════════════
                             TAB: General
                             ══════════════════════════════════ -->
                        <div class="tab-pane <?php echo $active_tab !== 'logs' ? 'active' : ''; ?>" id="tab-general">

                        <?php echo form_open(admin_url('pitchsnap/settings')); ?>

                        <!-- ================================================
                             Provider Selection
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Provider Selection</h5>

                        <div class="form-group">
                            <label for="pitchsnap_primary_provider">Primary Provider</label>
                            <select name="pitchsnap_primary_provider" id="pitchsnap_primary_provider" class="form-control" style="max-width:300px;">
                                <?php $primary = get_option('pitchsnap_primary_provider') ?: 'manus'; ?>
                                <option value="manus"     <?php echo $primary === 'manus'     ? 'selected' : ''; ?>>Manus</option>
                                <option value="anthropic" <?php echo $primary === 'anthropic' ? 'selected' : ''; ?>>Anthropic</option>
                            </select>
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Manus generates and publishes a live website. Anthropic generates an HTML redesign.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_fallback_provider">Fallback Provider</label>
                            <select name="pitchsnap_fallback_provider" id="pitchsnap_fallback_provider" class="form-control" style="max-width:300px;">
                                <?php $fallback = get_option('pitchsnap_fallback_provider') ?: 'anthropic'; ?>
                                <option value="anthropic" <?php echo $fallback === 'anthropic' ? 'selected' : ''; ?>>Anthropic</option>
                                <option value="none"      <?php echo $fallback === 'none'      ? 'selected' : ''; ?>>None</option>
                            </select>
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Used automatically when Manus credits are exhausted. Staff can also manually retry with Anthropic from any failed redesign.
                            </p>
                        </div>

                        <hr>

                        <!-- ================================================
                             Manus
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Manus</h5>

                        <div class="form-group">
                            <label for="pitchsnap_manus_api_key">Manus API Key</label>
                            <input type="password"
                                   name="pitchsnap_manus_api_key"
                                   id="pitchsnap_manus_api_key"
                                   class="form-control"
                                   autocomplete="new-password"
                                   style="max-width:480px;"
                                   placeholder="<?php echo get_option('pitchsnap_manus_api_key') ? '●●●●●●●●●● (saved — leave blank to keep)' : 'Your Manus API key'; ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Stored server-side only. Never exposed in page source or JavaScript. Leave blank to keep the existing key.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_manus_prompt">Manus Generation Prompt</label>
                            <textarea name="pitchsnap_manus_prompt"
                                      id="pitchsnap_manus_prompt"
                                      class="form-control"
                                      rows="28"
                                      style="font-family: monospace; font-size: 12px;"><?php echo e(get_option('pitchsnap_manus_prompt')); ?></textarea>
                            <p class="text-muted" style="margin-top:6px;font-size:12px;">
                                Supported placeholders:
                                <code>{{business_name}}</code>
                                <code>{{website_url}}</code>
                                <code>{{email}}</code>
                                <code>{{phone}}</code>
                                <code>{{role}}</code>
                                <code>{{company_size}}</code>
                                <code>{{desired_improvement}}</code>
                                <code>{{vertical}}</code>
                                <code>{{source_content}}</code>
                                <code>{{preview_token}}</code>
                            </p>
                        </div>

                        <hr>

                        <!-- ================================================
                             Anthropic
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Anthropic</h5>

                        <div class="form-group">
                            <label for="pitchsnap_anthropic_api_key">Anthropic API Key</label>
                            <input type="password"
                                   name="pitchsnap_anthropic_api_key"
                                   id="pitchsnap_anthropic_api_key"
                                   class="form-control"
                                   autocomplete="new-password"
                                   style="max-width:480px;"
                                   placeholder="<?php echo get_option('pitchsnap_anthropic_api_key') ? '●●●●●●●●●● (saved — leave blank to keep)' : 'sk-ant-...'; ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Used when Anthropic is the primary or fallback provider. Leave blank to keep the existing key.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_model">Anthropic Model</label>
                            <input type="text"
                                   name="pitchsnap_model"
                                   id="pitchsnap_model"
                                   class="form-control"
                                   style="max-width:400px;"
                                   value="<?php echo e(get_option('pitchsnap_model') ?: 'claude-sonnet-4-6'); ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Model ID. Example: <code>claude-sonnet-4-6</code>
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_generation_prompt">Anthropic Generation Prompt</label>
                            <textarea name="pitchsnap_generation_prompt"
                                      id="pitchsnap_generation_prompt"
                                      class="form-control"
                                      rows="16"
                                      style="font-family: monospace; font-size: 12px;"><?php echo e(get_option('pitchsnap_generation_prompt')); ?></textarea>
                            <p class="text-muted" style="margin-top:6px;font-size:12px;">
                                Supported placeholders:
                                <code>{{business_name}}</code>
                                <code>{{website_url}}</code>
                                <code>{{email}}</code>
                                <code>{{phone}}</code>
                                <code>{{role}}</code>
                                <code>{{company_size}}</code>
                                <code>{{desired_improvement}}</code>
                                <code>{{vertical}}</code>
                                <code>{{source_content}}</code>
                                <code>{{preview_token}}</code>
                            </p>
                        </div>

                        <hr>

                        <!-- ================================================
                             Generation Guardrails
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Generation Guardrails</h5>
                        <p class="text-muted" style="font-size:12px;margin-bottom:12px;">
                            Controls behavioral instructions sent to each provider. Turning a guardrail off suppresses the corresponding prompt instruction — it does not disable source intelligence (image classification, brand color extraction, etc.), which always runs.
                        </p>

                        <?php
                        $gr_labels = [
                            'logo_usage'               => 'Logo Usage',
                            'image_selection'          => 'Image Selection',
                            'team_placement'           => 'Team Placement',
                            'team_association'         => 'Team Association',
                            'anonymous_team'           => 'Anonymous Team',
                            'gallery_usage'            => 'Gallery Usage',
                            'credential_usage'         => 'Credential Usage',
                            'owner_story'              => 'Owner Story',
                            'visual_readability'       => 'Visual Readability',
                            'brand_color_preservation' => 'Brand Color Preservation',
                        ];
                        ?>
                        <table class="table table-condensed" style="max-width:500px;">
                            <thead>
                                <tr>
                                    <th style="width:60%;">Guardrail</th>
                                    <th class="text-center" style="width:20%;">Anthropic</th>
                                    <th class="text-center" style="width:20%;">Manus</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gr_labels as $gk => $gl): ?>
                                <tr>
                                    <td style="vertical-align:middle;"><?php echo $gl; ?></td>
                                    <td class="text-center" style="vertical-align:middle;">
                                        <input type="hidden"   name="pitchsnap_guardrail_anthropic_<?php echo $gk; ?>" value="0">
                                        <input type="checkbox" name="pitchsnap_guardrail_anthropic_<?php echo $gk; ?>" value="1" <?php echo !empty($guardrail_values['anthropic'][$gk]) ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center" style="vertical-align:middle;">
                                        <input type="hidden"   name="pitchsnap_guardrail_manus_<?php echo $gk; ?>" value="0">
                                        <input type="checkbox" name="pitchsnap_guardrail_manus_<?php echo $gk; ?>" value="1" <?php echo !empty($guardrail_values['manus'][$gk]) ? 'checked' : ''; ?>>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <hr>

                        <!-- ================================================
                             GoHighLevel
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">GoHighLevel</h5>

                        <div class="form-group">
                            <label for="pitchsnap_ghl_api_key">Agency Private Integration Token</label>
                            <input type="password"
                                   name="pitchsnap_ghl_api_key"
                                   id="pitchsnap_ghl_api_key"
                                   class="form-control"
                                   autocomplete="new-password"
                                   style="max-width:480px;"
                                   placeholder="<?php echo get_option('pitchsnap_ghl_api_key') ? '●●●●●●●●●● (saved — leave blank to keep)' : 'eyJ...'; ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Found in GHL → Settings → Integrations → Private Integrations. Required scope: <code>locations.readonly</code>.
                                Leave blank to keep the existing token.
                            </p>
                        </div>

                        <hr>

                        <!-- ================================================
                             Operational
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Operational</h5>

                        <div class="form-group">
                            <div class="checkbox">
                                <label>
                                    <input type="hidden"   name="pitchsnap_logging_enabled" value="0">
                                    <input type="checkbox" name="pitchsnap_logging_enabled" value="1" <?php echo get_option('pitchsnap_logging_enabled') ? 'checked' : ''; ?>>
                                    Enable activity logging
                                </label>
                            </div>
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Records generation events, errors, and lifecycle transitions in the activity log.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_web_design_admin">Web Design Admin</label>
                            <select name="pitchsnap_web_design_admin" id="pitchsnap_web_design_admin" class="form-control" style="max-width:300px;">
                                <?php $current_admin = (int) get_option('pitchsnap_web_design_admin'); ?>
                                <option value="0">— None —</option>
                                <?php foreach ($staff_list as $s): ?>
                                <option value="<?php echo (int) $s->staffid; ?>" <?php echo $current_admin === (int) $s->staffid ? 'selected' : ''; ?>>
                                    <?php echo e($s->firstname . ' ' . $s->lastname); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Staff member notified when a website is ready for approval.
                            </p>
                        </div>

                        <hr>

                        <!-- ================================================
                             Cloudflare
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Cloudflare</h5>
                        <p class="text-muted" style="font-size:12px; margin-bottom:12px;">
                            Required for automatic Custom Hostname provisioning when a custom domain is saved.
                            Create an API Token in Cloudflare → My Profile → API Tokens with
                            <strong>Zone:Custom Hostnames:Edit</strong> permission on the clickfuzz.com zone.
                            The Zone ID is on your Cloudflare zone dashboard.
                        </p>

                        <div class="form-group">
                            <label for="pitchsnap_cf_api_token">Cloudflare API Token</label>
                            <input type="password"
                                   name="pitchsnap_cf_api_token"
                                   id="pitchsnap_cf_api_token"
                                   class="form-control"
                                   autocomplete="new-password"
                                   style="max-width:480px;"
                                   placeholder="<?php echo get_option('pitchsnap_cf_api_token') ? '●●●●●●●●●● (saved — leave blank to keep)' : 'Your Cloudflare API Token'; ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">Stored server-side only. Leave blank to keep the existing token.</p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_cf_zone_id">Cloudflare Zone ID</label>
                            <input type="text"
                                   name="pitchsnap_cf_zone_id"
                                   id="pitchsnap_cf_zone_id"
                                   class="form-control"
                                   style="max-width:480px;"
                                   value="<?php echo e(get_option('pitchsnap_cf_zone_id') ?: ''); ?>"
                                   placeholder="clickfuzz.com Zone ID">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">The Zone ID for clickfuzz.com from the Cloudflare dashboard overview.</p>
                        </div>

                        <h5 class="tw-font-semibold mtop20 mbot10">Apex Redirect API</h5>
                        <div class="form-group">
                            <label for="pitchsnap_apex_api_token">Apex API Token</label>
                            <input type="password"
                                   name="pitchsnap_apex_api_token"
                                   id="pitchsnap_apex_api_token"
                                   class="form-control"
                                   style="max-width:480px;"
                                   value=""
                                   autocomplete="new-password"
                                   placeholder="<?php echo get_option('pitchsnap_apex_api_token') ? '●●●●●●●●●● (saved — leave blank to keep)' : 'Bearer token for apex-api.clickfuzz.com'; ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">Used to provision apex (root domain) redirects via the ClickFuzz Apex API. Leave blank to keep the existing token.</p>
                        </div>

                        <input type="hidden" name="active_tab" value="general">

                        <div class="mbot20">
                            <button type="submit" class="btn btn-primary">Save Settings</button>
                            <a href="<?php echo admin_url('pitchsnap/redesigns'); ?>" class="btn btn-default mls">Cancel</a>
                        </div>

                        <?php echo form_close(); ?>

                        </div><!-- #tab-general -->

                        <!-- ══════════════════════════════════
                             TAB: Logs
                             ══════════════════════════════════ -->
                        <div class="tab-pane <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" id="tab-logs">

                            <div class="row" style="margin-bottom:12px;">
                                <div class="col-xs-12">
                                    <form method="POST" action="<?php echo admin_url('pitchsnap/clear_logs'); ?>" style="display:inline;" onsubmit="return confirm('Clear all ClickFuzz Web logs?');">
                                        <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                        <button type="submit" class="btn btn-default btn-sm pull-right">
                                            <i class="fa fa-trash"></i> Clear Logs
                                        </button>
                                    </form>
                                    <p class="text-muted" style="font-size:12px; margin:6px 0 0;">
                                        Most recent <?php echo count($logs); ?> entries (cap 500).
                                    </p>
                                </div>
                            </div>

                            <?php if (empty($logs)) { ?>
                            <p class="text-muted" style="font-size:13px;">No log entries yet.</p>
                            <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table table-condensed table-bordered" style="font-size:12px; font-family:monospace;">
                                    <thead>
                                        <tr>
                                            <th style="width:140px; font-family:sans-serif;">Time</th>
                                            <th style="width:100px; font-family:sans-serif;">Context</th>
                                            <th style="font-family:sans-serif;">Message</th>
                                            <th style="font-family:sans-serif;">Data</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log) { ?>
                                        <tr>
                                            <td style="white-space:nowrap; color:#888;"><?php echo e($log['created_at']); ?></td>
                                            <td><span class="label label-default"><?php echo e($log['context']); ?></span></td>
                                            <td><?php echo e($log['message']); ?></td>
                                            <td style="color:#666; max-width:300px; word-break:break-all;">
                                                <?php echo !empty($log['data_json']) ? e($log['data_json']) : ''; ?>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php } ?>

                        </div><!-- #tab-logs -->

                        </div><!-- .tab-content -->

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
