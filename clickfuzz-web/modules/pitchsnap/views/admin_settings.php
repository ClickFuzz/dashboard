<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mb-4">ClickFuzz Web Settings</h4>

                        <?php echo form_open(admin_url('pitchsnap/settings')); ?>

                        <h5 class="tw-font-semibold mtop20 mbot10">AI Provider</h5>

                        <div class="form-group">
                            <label for="pitchsnap_ai_provider">Provider</label>
                            <select name="pitchsnap_ai_provider" id="pitchsnap_ai_provider" class="form-control" style="max-width:300px;">
                                <option value="anthropic" <?php echo (get_option('pitchsnap_ai_provider') ?: 'anthropic') === 'anthropic' ? 'selected' : ''; ?>>Anthropic</option>
                            </select>
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">Additional providers can be wired in without redesigning the module.</p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_anthropic_api_key">Anthropic API Key</label>
                            <input type="password"
                                   name="pitchsnap_anthropic_api_key"
                                   id="pitchsnap_anthropic_api_key"
                                   class="form-control"
                                   autocomplete="new-password"
                                   style="max-width:480px;"
                                   placeholder="<?php echo get_option('pitchsnap_anthropic_api_key') ? '●●●●●●●●●● (saved — leave blank to keep current key)' : 'sk-ant-...'; ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Stored server-side only. Never exposed in page source or client JavaScript.
                                Leave blank to keep the existing key.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="pitchsnap_model">Model</label>
                            <input type="text"
                                   name="pitchsnap_model"
                                   id="pitchsnap_model"
                                   class="form-control"
                                   style="max-width:400px;"
                                   value="<?php echo e(get_option('pitchsnap_model') ?: 'claude-sonnet-4-6'); ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Anthropic model ID. Examples: <code>claude-sonnet-4-6</code>, <code>claude-haiku-4-5-20251001</code>
                            </p>
                        </div>

                        <hr>

                        <h5 class="tw-font-semibold mtop20 mbot10">Master Generation Prompt</h5>

                        <div class="form-group">
                            <label for="pitchsnap_generation_prompt">Prompt Template</label>
                            <textarea name="pitchsnap_generation_prompt"
                                      id="pitchsnap_generation_prompt"
                                      class="form-control"
                                      rows="20"
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
                                <code>{{vertical}}</code><br>
                                The rendered prompt (with placeholders filled) is stored permanently on each redesign record so history is preserved when you edit this template later.
                            </p>
                        </div>

                        <hr>

                        <h5 class="tw-font-semibold mtop20 mbot10">GoHighLevel</h5>

                        <div class="form-group">
                            <label for="pitchsnap_ghl_api_key">Agency Private Integration Token</label>
                            <input type="password"
                                   name="pitchsnap_ghl_api_key"
                                   id="pitchsnap_ghl_api_key"
                                   class="form-control"
                                   autocomplete="new-password"
                                   style="max-width:480px;"
                                   placeholder="<?php echo get_option('pitchsnap_ghl_api_key') ? '●●●●●●●●●● (saved — leave blank to keep current token)' : 'eyJ...'; ?>">
                            <p class="text-muted" style="margin-top:4px;font-size:12px;">
                                Agency Private Integration Token. Stored server-side only. Leave blank to keep the existing token.
                                Found in GHL → Settings → Integrations → Private Integrations. Required scope: <code>locations.readonly</code>.
                            </p>
                        </div>
                        <input type="hidden" name="active_tab" value="ghl">

                        <div class="mbot20">
                            <button type="submit" class="btn btn-primary">Save Settings</button>
                            <a href="<?php echo admin_url('pitchsnap/redesigns'); ?>" class="btn btn-default mls">Cancel</a>
                        </div>

                        <?php echo form_close(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
