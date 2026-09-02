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
                            <li class="<?php echo $active_tab !== 'logs' && $active_tab !== 'ghl-destinations' ? 'active' : ''; ?>">
                                <a href="#tab-general" data-toggle="tab">General</a>
                            </li>
                            <li class="<?php echo $active_tab === 'ghl-destinations' ? 'active' : ''; ?>">
                                <a href="#tab-ghl-destinations" data-toggle="tab">GHL Destinations</a>
                            </li>
                            <li class="<?php echo $active_tab === 'logs' ? 'active' : ''; ?>">
                                <a href="#tab-logs" data-toggle="tab">Logs</a>
                            </li>
                        </ul>

                        <div class="tab-content">

                        <!-- ══════════════════════════════════
                             TAB: General
                             ══════════════════════════════════ -->
                        <div class="tab-pane <?php echo ($active_tab !== 'logs' && $active_tab !== 'ghl-destinations') ? 'active' : ''; ?>" id="tab-general">

                        <?php echo form_open(admin_url('pitchsnap/settings')); ?>
                        <input type="hidden" name="pitchsnap_general_submitted" value="1">

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

                        <hr>

                        <!-- ================================================
                             Sales Flow
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Sales Flow</h5>

                        <div class="form-group">
                            <label for="pitchsnap_video_demo_url">Video Sales Letter URL</label>
                            <input type="text"
                                   name="pitchsnap_video_demo_url"
                                   id="pitchsnap_video_demo_url"
                                   class="form-control"
                                   style="max-width:480px;"
                                   value="<?php echo e(get_option('pitchsnap_video_demo_url') ?: ''); ?>"
                                   placeholder="https://www.youtube.com/watch?v=... or Vimeo URL">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Shown in the sales modal when a prospect clicks "I like it". Accepts YouTube or Vimeo URLs — automatically converted to embed format. Leave blank to hide the video section.
                            </p>
                        </div>

                        <hr>

                        <!-- ================================================
                             Agreement
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Service Agreement</h5>

                        <div class="form-group">
                            <label for="pitchsnap_agreement_version">Agreement Version</label>
                            <input type="text"
                                   name="pitchsnap_agreement_version"
                                   id="pitchsnap_agreement_version"
                                   class="form-control"
                                   style="max-width:120px;"
                                   value="<?php echo e(get_option('pitchsnap_agreement_version') ?: '1.0'); ?>"
                                   placeholder="1.0">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Increment when the agreement text changes. Stored on each signed agreement record for audit purposes.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_agreement_text">Agreement Text</label>
                            <textarea name="pitchsnap_agreement_text"
                                      id="pitchsnap_agreement_text"
                                      class="form-control"
                                      rows="18"
                                      style="font-family: monospace; font-size: 12px;"><?php echo e(get_option('pitchsnap_agreement_text')); ?></textarea>
                            <p class="text-muted" style="margin-top:6px;font-size:12px;">
                                Plain text displayed on the prospect agreement page. Leave blank to use the built-in default placeholder.
                                <strong>Have this reviewed by an attorney before use in production.</strong>
                            </p>
                        </div>

                        <hr>

                        <!-- ================================================
                             Pricing & Stripe
                             ================================================ -->
                        <h5 class="tw-font-semibold mtop20 mbot10">Pricing &amp; Stripe</h5>

                        <div class="form-group">
                            <label for="pitchsnap_payment_type">Payment Type</label>
                            <select name="pitchsnap_payment_type" id="pitchsnap_payment_type" class="form-control" style="max-width:220px;">
                                <?php $pay_type = get_option('pitchsnap_payment_type') ?: 'onetime'; ?>
                                <option value="onetime"      <?php echo $pay_type === 'onetime'      ? 'selected' : ''; ?>>One-time invoice</option>
                                <option value="subscription" <?php echo $pay_type === 'subscription' ? 'selected' : ''; ?>>Subscription</option>
                            </select>
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                One-time: creates a Perfex invoice and redirects to Stripe Checkout.
                                Subscription: creates a Perfex subscription and redirects to a Stripe subscription checkout.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_price">Price (USD)</label>
                            <div class="input-group" style="max-width:180px;">
                                <span class="input-group-addon">$</span>
                                <input type="text"
                                       name="pitchsnap_price"
                                       id="pitchsnap_price"
                                       class="form-control"
                                       value="<?php echo e(get_option('pitchsnap_price') ?: '295.00'); ?>"
                                       placeholder="295.00">
                            </div>
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Used for one-time invoice creation and displayed in the sales modal. Enter a decimal amount (e.g. <code>295.00</code>).
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_stripe_plan_id">Stripe Price / Plan ID</label>
                            <input type="text"
                                   name="pitchsnap_stripe_plan_id"
                                   id="pitchsnap_stripe_plan_id"
                                   class="form-control"
                                   style="max-width:380px;"
                                   value="<?php echo e(get_option('pitchsnap_stripe_plan_id') ?: ''); ?>"
                                   placeholder="price_...">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Required for the subscription flow. Find it in Stripe → Products → your plan → Pricing.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_sub_name">Subscription Name</label>
                            <input type="text"
                                   name="pitchsnap_sub_name"
                                   id="pitchsnap_sub_name"
                                   class="form-control"
                                   style="max-width:380px;"
                                   value="<?php echo e(get_option('pitchsnap_sub_name') ?: 'PitchSnap Monthly Service'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_sub_description">Subscription Description</label>
                            <textarea name="pitchsnap_sub_description"
                                      id="pitchsnap_sub_description"
                                      class="form-control"
                                      rows="3"
                                      style="max-width:480px;"><?php echo e(get_option('pitchsnap_sub_description') ?: ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_sub_quantity">Quantity</label>
                            <input type="number"
                                   name="pitchsnap_sub_quantity"
                                   id="pitchsnap_sub_quantity"
                                   class="form-control"
                                   style="max-width:100px;"
                                   min="1"
                                   value="<?php echo (int)(get_option('pitchsnap_sub_quantity') ?: 1); ?>">
                        </div>

                        <div class="form-group">
                            <label style="font-weight:normal;">
                                <input type="hidden"   name="pitchsnap_sub_include_desc" value="0">
                                <input type="checkbox" name="pitchsnap_sub_include_desc" value="1" <?php echo get_option('pitchsnap_sub_include_desc') === '1' ? 'checked' : ''; ?>>
                                Include description as line item
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_sub_tax1">Stripe Tax Rate ID 1</label>
                            <input type="text"
                                   name="pitchsnap_sub_tax1"
                                   id="pitchsnap_sub_tax1"
                                   class="form-control"
                                   style="max-width:320px;"
                                   value="<?php echo e(get_option('pitchsnap_sub_tax1') ?: ''); ?>"
                                   placeholder="txr_... (optional)">
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_sub_tax2">Stripe Tax Rate ID 2</label>
                            <input type="text"
                                   name="pitchsnap_sub_tax2"
                                   id="pitchsnap_sub_tax2"
                                   class="form-control"
                                   style="max-width:320px;"
                                   value="<?php echo e(get_option('pitchsnap_sub_tax2') ?: ''); ?>"
                                   placeholder="txr_... (optional)">
                        </div>

                        <input type="hidden" name="active_tab" value="general">

                        <div class="mbot20">
                            <button type="submit" class="btn btn-primary">Save Settings</button>
                            <a href="<?php echo admin_url('pitchsnap/redesigns'); ?>" class="btn btn-default mls">Cancel</a>
                        </div>

                        <?php echo form_close(); ?>

                        </div><!-- #tab-general -->

                        <!-- ══════════════════════════════════
                             TAB: GHL Destinations
                             ══════════════════════════════════ -->
                        <div class="tab-pane <?php echo $active_tab === 'ghl-destinations' ? 'active' : ''; ?>" id="tab-ghl-destinations">

                            <div class="row mbot15">
                                <div class="col-xs-12">
                                    <p class="text-muted" style="font-size:13px;">
                                        Global GHL destination definitions. <strong>Single Input</strong> destinations accept one form field value; <strong>Multiple Inputs</strong> destinations aggregate all mapped field values into one GHL custom field.
                                    </p>
                                    <button class="btn btn-primary btn-sm" onclick="ghlDestOpenModal(null)">
                                        <i class="fa fa-plus"></i> Add Destination
                                    </button>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-condensed table-bordered" id="ghl-dest-table" style="font-size:13px;">
                                    <thead>
                                        <tr>
                                            <th>Label</th>
                                            <th>GHL Field Key / UUID</th>
                                            <th>Mode</th>
                                            <th style="width:110px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ghl-dest-tbody">
                                        <?php foreach ($ghl_destinations as $dest) { ?>
                                        <tr id="ghl-dest-row-<?php echo (int) $dest->id; ?>">
                                            <td><?php echo e($dest->label); ?></td>
                                            <td><code><?php echo e($dest->ghl_key !== '' ? $dest->ghl_key : '—'); ?></code></td>
                                            <td>
                                                <?php if ($dest->mode === 'multiple') { ?>
                                                <span class="label label-info">Multiple Inputs</span>
                                                <?php } else { ?>
                                                <span class="label label-default">Single Input</span>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-xs btn-default" onclick="ghlDestOpenModal(<?php echo (int) $dest->id; ?>)">Edit</button>
                                                <button class="btn btn-xs btn-danger" onclick="ghlDestDelete(<?php echo (int) $dest->id; ?>)">Del</button>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                        <?php if (empty($ghl_destinations)) { ?>
                                        <tr id="ghl-dest-empty"><td colspan="4" class="text-muted text-center">No destinations yet.</td></tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>

                        </div><!-- #tab-ghl-destinations -->

                        <!-- GHL Destination Modal -->
                        <div class="modal fade" id="ghl-dest-modal" tabindex="-1" role="dialog">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                        <h4 class="modal-title" id="ghl-dest-modal-title">Add Destination</h4>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" id="ghl-dest-id" value="">
                                        <div class="form-group">
                                            <label>Label</label>
                                            <input type="text" id="ghl-dest-label" class="form-control" placeholder="e.g. Quote Content">
                                        </div>
                                        <div class="form-group">
                                            <label>GHL Field Key or UUID</label>
                                            <input type="text" id="ghl-dest-key" class="form-control" placeholder="e.g. firstName  or  uuid-...">
                                            <p class="help-block" style="font-size:11px;">Standard contact fields: firstName, lastName, email, phone. Custom fields: paste the GHL custom field UUID. Leave blank for Multiple Inputs aggregation destinations.</p>
                                        </div>
                                        <div class="form-group">
                                            <label>Mode</label>
                                            <select id="ghl-dest-mode" class="form-control">
                                                <option value="single">Single Input — one form field maps here</option>
                                                <option value="multiple">Multiple Inputs — aggregate all mapped fields</option>
                                            </select>
                                        </div>
                                        <div id="ghl-dest-error" class="alert alert-danger" style="display:none;"></div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                                        <button type="button" class="btn btn-primary" id="ghl-dest-save-btn" onclick="ghlDestSave()">Save</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <script>
                        var _ghlDests = <?php echo json_encode(array_values($ghl_destinations)); ?>;
                        var _ghlDestBase = '<?php echo admin_url('pitchsnap'); ?>';
                        var _ghlCsrfName = '<?php echo $this->security->get_csrf_token_name(); ?>';
                        var _ghlCsrfHash = '<?php echo $this->security->get_csrf_hash(); ?>';

                        function ghlDestOpenModal(id) {
                            var modal = $('#ghl-dest-modal');
                            $('#ghl-dest-error').hide().text('');
                            if (id) {
                                var dest = _ghlDests.find(function(d) { return d.id == id; });
                                if (!dest) { return; }
                                $('#ghl-dest-modal-title').text('Edit Destination');
                                $('#ghl-dest-id').val(dest.id);
                                $('#ghl-dest-label').val(dest.label);
                                $('#ghl-dest-key').val(dest.ghl_key);
                                $('#ghl-dest-mode').val(dest.mode);
                            } else {
                                $('#ghl-dest-modal-title').text('Add Destination');
                                $('#ghl-dest-id').val('');
                                $('#ghl-dest-label').val('');
                                $('#ghl-dest-key').val('');
                                $('#ghl-dest-mode').val('single');
                            }
                            modal.modal('show');
                        }

                        function ghlDestSave() {
                            var id    = $('#ghl-dest-id').val();
                            var label = $.trim($('#ghl-dest-label').val());
                            var key   = $.trim($('#ghl-dest-key').val());
                            var mode  = $('#ghl-dest-mode').val();
                            if (!label) { $('#ghl-dest-error').text('Label is required.').show(); return; }
                            var btn = $('#ghl-dest-save-btn').prop('disabled', true).text('Saving…');
                            var payload = { label: label, ghl_key: key, mode: mode };
                            if (id) { payload.id = id; }
                            payload[_ghlCsrfName] = _ghlCsrfHash;
                            $.post(_ghlDestBase + '/ghl_dest_save', payload, function(res) {
                                if (!res.success) {
                                    $('#ghl-dest-error').text(res.message || 'Save failed.').show();
                                    btn.prop('disabled', false).text('Save');
                                    return;
                                }
                                var d = res.destination;
                                var modeLabel = d.mode === 'multiple'
                                    ? '<span class="label label-info">Multiple Inputs</span>'
                                    : '<span class="label label-default">Single Input</span>';
                                var keyHtml = d.ghl_key !== '' ? '<code>' + d.ghl_key + '</code>' : '<code>—</code>';
                                var row = '<tr id="ghl-dest-row-' + d.id + '">'
                                    + '<td>' + $('<div>').text(d.label).html() + '</td>'
                                    + '<td>' + keyHtml + '</td>'
                                    + '<td>' + modeLabel + '</td>'
                                    + '<td>'
                                    +   '<button class="btn btn-xs btn-default" onclick="ghlDestOpenModal(' + d.id + ')">Edit</button> '
                                    +   '<button class="btn btn-xs btn-danger" onclick="ghlDestDelete(' + d.id + ')">Del</button>'
                                    + '</td>'
                                    + '</tr>';
                                if (id) {
                                    $('#ghl-dest-row-' + id).replaceWith(row);
                                    _ghlDests = _ghlDests.map(function(x) { return x.id == id ? d : x; });
                                } else {
                                    $('#ghl-dest-empty').remove();
                                    $('#ghl-dest-tbody').append(row);
                                    _ghlDests.push(d);
                                }
                                $('#ghl-dest-modal').modal('hide');
                                btn.prop('disabled', false).text('Save');
                                _ghlCsrfHash = res.csrf_hash || _ghlCsrfHash;
                            }, 'json').fail(function() {
                                $('#ghl-dest-error').text('Request failed.').show();
                                btn.prop('disabled', false).text('Save');
                            });
                        }

                        function ghlDestDelete(id) {
                            if (!confirm('Delete this destination? Forms currently using it will lose the mapping.')) { return; }
                            var payload = {};
                            payload[_ghlCsrfName] = _ghlCsrfHash;
                            $.post(_ghlDestBase + '/ghl_dest_delete/' + id, payload, function(res) {
                                if (!res.success) { alert(res.message || 'Delete failed.'); return; }
                                $('#ghl-dest-row-' + id).remove();
                                _ghlDests = _ghlDests.filter(function(x) { return x.id != id; });
                                if (_ghlDests.length === 0) {
                                    $('#ghl-dest-tbody').append('<tr id="ghl-dest-empty"><td colspan="4" class="text-muted text-center">No destinations yet.</td></tr>');
                                }
                                _ghlCsrfHash = res.csrf_hash || _ghlCsrfHash;
                            }, 'json').fail(function() { alert('Request failed.'); });
                        }

                        </script>

                        <!-- ══════════════════════════════════
                             TAB: Logs
                             ══════════════════════════════════ -->
                        <style>
                        #tab-logs input[type="checkbox"] {
                            opacity: 1 !important;
                            position: static !important;
                            width: 16px !important;
                            height: 16px !important;
                            cursor: pointer !important;
                            pointer-events: auto !important;
                        }
                        </style>
                        <div class="tab-pane <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" id="tab-logs">

                            <!-- ── Log category controls ── -->
                            <?php echo form_open(admin_url('pitchsnap/settings')); ?>
                            <input type="hidden" name="pitchsnap_log_cats_submitted" value="1">
                            <input type="hidden" name="active_tab" value="logs">
                            <div style="border:1px solid #ddd; border-radius:4px; padding:14px 16px; margin-bottom:20px; background:#fafafa;">
                                <div style="margin:0 0 14px;">
                                    <label style="font-size:13px; font-weight:600; cursor:pointer;">
                                        <input type="hidden"   name="pitchsnap_logging_enabled" value="0">
                                        <input type="checkbox" name="pitchsnap_logging_enabled" value="1" <?php echo get_option('pitchsnap_logging_enabled') ? 'checked' : ''; ?>>
                                        Enable activity logging
                                    </label>
                                    <p class="text-muted" style="margin:2px 0 0; font-size:12px; padding-left:20px;">Master switch — must be on for any log entries to be written.</p>
                                </div>
                                <p style="font-size:13px; font-weight:600; margin:0 0 4px;">Log Categories</p>
                                <p class="text-muted" style="font-size:12px; margin:0 0 12px;">Controls which flows write to the log.</p>
                                <table class="table table-condensed" style="max-width:600px; margin-bottom:10px;">
                                    <tbody>
                                        <tr>
                                            <td style="width:28px; vertical-align:middle;">
                                                <input type="hidden"   name="pitchsnap_log_stripe" value="0">
                                                <input type="checkbox" name="pitchsnap_log_stripe" value="1" <?php echo get_option('pitchsnap_log_stripe') ? 'checked' : ''; ?>>
                                            </td>
                                            <td style="vertical-align:middle;"><strong>Stripe &amp; Payments</strong></td>
                                            <td style="vertical-align:middle; color:#777; font-size:12px;">checkout sessions, subscription creation, invoice URL lookups</td>
                                        </tr>
                                        <tr>
                                            <td style="vertical-align:middle;">
                                                <input type="hidden"   name="pitchsnap_log_sales" value="0">
                                                <input type="checkbox" name="pitchsnap_log_sales" value="1" <?php echo get_option('pitchsnap_log_sales') ? 'checked' : ''; ?>>
                                            </td>
                                            <td style="vertical-align:middle;"><strong>Purchase &amp; Agreement</strong></td>
                                            <td style="vertical-align:middle; color:#777; font-size:12px;">purchase initiation, agreement acceptance, customer creation</td>
                                        </tr>
                                        <tr>
                                            <td style="vertical-align:middle;">
                                                <input type="hidden"   name="pitchsnap_log_generation" value="0">
                                                <input type="checkbox" name="pitchsnap_log_generation" value="1" <?php echo get_option('pitchsnap_log_generation') ? 'checked' : ''; ?>>
                                            </td>
                                            <td style="vertical-align:middle;"><strong>Generation Pipeline</strong></td>
                                            <td style="vertical-align:middle; color:#777; font-size:12px;">Manus/Anthropic jobs, completions, failures</td>
                                        </tr>
                                        <tr>
                                            <td style="vertical-align:middle;">
                                                <input type="hidden"   name="pitchsnap_log_ghl" value="0">
                                                <input type="checkbox" name="pitchsnap_log_ghl" value="1" <?php echo get_option('pitchsnap_log_ghl') ? 'checked' : ''; ?>>
                                            </td>
                                            <td style="vertical-align:middle;"><strong>GHL / Forms</strong></td>
                                            <td style="vertical-align:middle; color:#777; font-size:12px;">contact creation, failures, skipped submissions</td>
                                        </tr>
                                    </tbody>
                                </table>
                                <button type="submit" class="btn btn-primary btn-sm">Save Categories</button>
                            </div>
                            <?php echo form_close(); ?>

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
