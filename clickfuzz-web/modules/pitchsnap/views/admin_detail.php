<?php defined('BASEPATH') or exit('No direct script access allowed'); $redesign = $website ?? null; ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">

        <div class="row">
            <div class="col-md-12">
                <a href="<?php echo admin_url('pitchsnap/websites'); ?>" class="btn btn-default btn-sm mbot10">
                    <i class="fa fa-arrow-left"></i> All Websites
                </a>
                <?php if ($lead && (!empty($lead->company) || !empty($lead->name))) { ?>
                <h4 class="tw-font-semibold" style="margin-top:8px; margin-bottom:2px;">
                    <?php echo e(!empty($lead->company) ? $lead->company : $lead->name); ?>
                </h4>
                <?php } ?>
                <p class="text-muted" style="margin:0 0 12px;">
                    Website #<?php echo (int) $redesign->id; ?>&nbsp;
                    <?php echo ps_badge($redesign->status); ?>
                    <?php if (!empty($redesign->is_primary)) { ?>
                    &nbsp;<span class="label label-default"><i class="fa fa-star"></i> Primary</span>
                    <?php } ?>
                    <?php if (!empty($redesign->parent_redesign_id)) { ?>
                    &nbsp;<small class="text-muted">(regenerated from <a href="<?php echo admin_url('pitchsnap/detail/' . (int) $redesign->parent_redesign_id); ?>">version #<?php echo (int) $redesign->parent_redesign_id; ?></a>)</small>
                    <?php } ?>
                </p>
            </div>
        </div>

        <div class="row">

            <!-- ── Left column ──────────────────────────────────────────── -->
            <div class="col-md-8">

                <!-- Version History -->
                <?php if (!empty($versions) && count($versions) > 1) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Version History</h5>
                        <table class="table table-bordered table-condensed" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th width="6%">#</th>
                                    <th width="22%">Status</th>
                                    <th width="30%">Created</th>
                                    <th width="14%">Provider</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($versions as $v) { ?>
                                <tr<?php echo ($v->id == $redesign->id) ? ' class="active"' : ''; ?>>
                                    <td>
                                        #<?php echo (int) $v->id; ?>
                                        <?php if (!empty($v->is_primary)) { ?>&nbsp;<i class="fa fa-star text-muted" title="Primary"></i><?php } ?>
                                    </td>
                                    <td><?php echo ps_badge($v->status); ?></td>
                                    <td style="font-size:12px;"><?php echo _dt($v->dateadded); ?></td>
                                    <td style="font-size:12px;"><?php echo !empty($v->provider) ? e($v->provider) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="text-right" style="white-space:nowrap;">
                                        <?php if ($v->id != $redesign->id) { ?>
                                        <a href="<?php echo admin_url('pitchsnap/detail/' . (int) $v->id); ?>" class="btn btn-default btn-xs">View</a>
                                        <?php } ?>
                                        <?php if (empty($v->is_primary)) { ?>
                                        <form method="POST" action="<?php echo admin_url('pitchsnap/set_primary/' . (int) $v->id); ?>" style="display:inline;">
                                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                            <button type="submit" class="btn btn-default btn-xs"
                                                    onclick="return confirm('Set version #<?php echo (int) $v->id; ?> as primary?');">
                                                <i class="fa fa-star-o"></i> Set Primary
                                            </button>
                                        </form>
                                        <form method="POST" action="<?php echo admin_url('pitchsnap/delete_versions'); ?>" style="display:inline;">
                                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                            <input type="hidden" name="ids[]" value="<?php echo (int) $v->id; ?>">
                                            <input type="hidden" name="redirect_id" value="<?php echo (int) $redesign->id; ?>">
                                            <button type="submit" class="btn btn-danger btn-xs"
                                                    onclick="return confirm('Delete version #<?php echo (int) $v->id; ?>? This cannot be undone.');">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <!-- Intake Details -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Intake Details</h5>
                        <table class="table table-bordered table-condensed">
                            <tbody>
                                <tr>
                                    <th width="35%">Original Website</th>
                                    <td>
                                        <?php if (!empty($redesign->original_url)) { ?>
                                        <a href="<?php echo e($redesign->original_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($redesign->original_url); ?></a>
                                        <?php } else { ?><span class="text-muted">Not provided</span><?php } ?>
                                    </td>
                                </tr>
                                <tr><th>Vertical</th><td><?php echo e($redesign->vertical); ?></td></tr>
                                <tr>
                                    <th>Role</th>
                                    <td><?php echo !empty($redesign->intake_role) ? e($redesign->intake_role) : '<span class="text-muted">—</span>'; ?></td>
                                </tr>
                                <tr>
                                    <th>Company Size</th>
                                    <td><?php echo !empty($redesign->intake_company_size) ? e($redesign->intake_company_size) : '<span class="text-muted">—</span>'; ?></td>
                                </tr>
                                <tr>
                                    <th>Desired Improvement</th>
                                    <td><?php echo !empty($redesign->intake_improvement) ? e($redesign->intake_improvement) : '<span class="text-muted">—</span>'; ?></td>
                                </tr>
                                <tr><th>Created</th><td><?php echo _dt($redesign->dateadded); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Generation -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Generation</h5>

                        <table class="table table-bordered table-condensed mbot15">
                            <tbody>
                                <tr>
                                    <th width="35%">Status</th>
                                    <td><?php echo ps_badge($redesign->status); ?></td>
                                </tr>
                                <tr>
                                    <th>Provider</th>
                                    <td><?php echo !empty($redesign->provider) ? e($redesign->provider) : '<span class="text-muted">—</span>'; ?></td>
                                </tr>
                                <tr>
                                    <th>Model</th>
                                    <td><?php echo !empty($redesign->model_used) ? e($redesign->model_used) : '<span class="text-muted">—</span>'; ?></td>
                                </tr>
                                <tr>
                                    <th>Generated At</th>
                                    <td><?php echo $redesign->generated_at ? _dt($redesign->generated_at) : '<span class="text-muted">—</span>'; ?></td>
                                </tr>
                                <?php if (!empty($redesign->generation_error)) { ?>
                                <tr>
                                    <th>Error</th>
                                    <td class="text-danger"><code style="font-size:11px;"><?php echo e($redesign->generation_error); ?></code></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>

                        <?php $s = $redesign->status; ?>

                        <?php if (in_array($s, ['new', 'pending'])) { ?>
                        <button class="btn btn-primary" onclick="ps_queue_generate(<?php echo (int) $redesign->id; ?>)">
                            <i class="fa fa-bolt"></i> Generate Website
                        </button>

                        <?php } elseif (in_array($s, ['pending_generation', 'generating', 'publishing', 'modifying'])) { ?>
                        <p class="text-muted" style="margin-bottom:0;">
                            <i class="fa fa-spinner fa-spin"></i>
                            <?php
                                echo $s === 'generating'  ? 'Generation in progress…'
                                   : ($s === 'publishing'  ? 'Publishing in progress…'
                                   : ($s === 'modifying'   ? 'AI modification in progress…'
                                   : 'Queued — awaiting next cron run.'));
                            ?>
                        </p>

                        <?php } elseif ($s === 'review_required') { ?>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/approve_design/' . (int) $redesign->id); ?>" style="display:inline;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-success mright5"
                                    onclick="return confirm('Approve this design and notify the prospect?');">
                                <i class="fa fa-check"></i> Approve &amp; Send
                            </button>
                        </form>
                        <a href="<?php echo admin_url('pitchsnap/regenerate/' . (int) $redesign->id); ?>"
                           class="btn btn-default"
                           onclick="return confirm('Create a new version from this one?');">
                            <i class="fa fa-refresh"></i> Regenerate
                        </a>

                        <?php } elseif (in_array($s, ['approved', 'sent', 'viewed'])) { ?>
                        <a href="<?php echo admin_url('pitchsnap/regenerate/' . (int) $redesign->id); ?>"
                           class="btn btn-default"
                           onclick="return confirm('Create a new version from this one?');">
                            <i class="fa fa-refresh"></i> Regenerate
                        </a>

                        <?php } elseif ($s === 'failed') { ?>
                        <button class="btn btn-primary mright5" onclick="ps_queue_generate(<?php echo (int) $redesign->id; ?>)">
                            <i class="fa fa-refresh"></i> Retry Generation
                        </button>
                        <a href="<?php echo admin_url('pitchsnap/retry_anthropic/' . (int) $redesign->id); ?>"
                           class="btn btn-default mright5">
                            <i class="fa fa-cloud"></i> Retry with Anthropic
                        </a>
                        <a href="<?php echo admin_url('pitchsnap/regenerate/' . (int) $redesign->id); ?>"
                           class="btn btn-default"
                           onclick="return confirm('Create a new version from this one?');">
                            <i class="fa fa-copy"></i> New Version
                        </a>
                        <?php } ?>

                        <!-- Rendered prompt (collapsible) -->
                        <?php if (!empty($redesign->generation_prompt)) { ?>
                        <div class="mtop15">
                            <a href="#" onclick="$('#ps_prompt_block').toggle(); return false;" class="text-muted" style="font-size:12px;">
                                <i class="fa fa-code"></i> Show/hide rendered prompt
                            </a>
                            <div id="ps_prompt_block" style="display:none; margin-top:8px;">
                                <textarea class="form-control" readonly rows="12"
                                          style="font-family:monospace; font-size:11px; resize:vertical;"
                                ><?php echo e($redesign->generation_prompt); ?></textarea>
                            </div>
                        </div>
                        <?php } ?>

                    </div>
                </div>

                <!-- Preview / HTML actions -->
                <?php if (!empty($redesign->preview_url) || !empty($redesign->generation_result)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between mbot10">
                            <h5 class="tw-font-semibold" style="margin:0;">Preview &amp; HTML</h5>
                            <div>
                                <?php if (!empty($redesign->preview_url)) { ?>
                                <a href="<?php echo e($redesign->preview_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm mright5">
                                    <i class="fa fa-globe"></i> Open Preview
                                </a>
                                <?php } ?>
                                <?php if (!empty($redesign->generation_result)) { ?>
                                <a href="<?php echo admin_url('pitchsnap/edit_html/' . (int) $redesign->id); ?>" class="btn btn-default btn-sm mright5">
                                    <i class="fa fa-code"></i> Edit HTML
                                </a>
                                <button class="btn btn-default btn-sm" onclick="$('#ps_modify_panel').toggle();">
                                    <i class="fa fa-magic"></i> AI Modify
                                </button>
                                <?php } ?>
                                <?php if (!empty($redesign->preview_url)) { ?>
                                <form method="POST" action="<?php echo admin_url('pitchsnap/delete_preview/' . (int) $redesign->id); ?>" style="display:inline; margin-left:4px;">
                                    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Delete preview files? The website record will be preserved.');">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                                <?php } ?>
                            </div>
                        </div>

                        <?php if (!empty($site)) { ?>
                        <div class="mtop10">
                            <form method="POST" action="<?php echo admin_url('pitchsnap/publish_site/' . (int) $redesign->id); ?>" style="display:inline;">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <button type="submit" class="btn btn-primary btn-sm"
                                        onclick="return confirm('Publish this site to its permanent URL?');">
                                    <i class="fa fa-upload"></i> Publish Site
                                </button>
                            </form>
                        </div>
                        <?php } ?>

                        <!-- AI Modify panel -->
                        <div id="ps_modify_panel" style="display:none; margin-top:12px; padding-top:12px; border-top:1px solid #eee;">
                            <p class="text-muted" style="font-size:12px; margin-bottom:6px;">Describe the changes to apply. The AI will edit only what you specify and return the full updated HTML.</p>
                            <textarea id="ps_modify_request" class="form-control" rows="3"
                                      placeholder="e.g. Change the hero headline to 'Trusted Local Plumbers'…"></textarea>
                            <button class="btn btn-primary btn-sm mtop10" onclick="ps_modify_html(<?php echo (int) $redesign->id; ?>)">
                                <i class="fa fa-magic"></i> Apply Changes
                            </button>
                            <span id="ps_modify_status" class="text-muted" style="font-size:12px; margin-left:10px;"></span>
                        </div>
                    </div>
                </div>
                <?php } ?>

                <!-- Prospect Conversations -->
                <?php if (!empty($conversations)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Prospect Conversation</h5>
                        <table class="table table-bordered table-condensed">
                            <thead>
                                <tr><th width="30%">Date</th><th>Response</th></tr>
                            </thead>
                            <tbody>
                            <?php
                            $reply_labels = [
                                'like'       => '<span class="label label-success">I like it</span>',
                                'change'     => '<span class="label label-warning">I\'d change a few things</span>',
                                'not_for_me' => '<span class="label label-default">Not for me</span>',
                            ];
                            foreach ($conversations as $conv) { ?>
                            <tr>
                                <td style="white-space:nowrap; vertical-align:top;"><?php echo _dt($conv['created_at']); ?></td>
                                <td>
                                    <?php echo $reply_labels[$conv['quick_reply']] ?? e($conv['quick_reply']); ?>
                                    <?php if (!empty($conv['change_request'])) { ?>
                                    <br><span class="text-muted" style="font-size:12px; font-style:italic;">"<?php echo e($conv['change_request']); ?>"</span>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

            </div><!-- /.col-md-8 -->

            <!-- ── Right column ─────────────────────────────────────────── -->
            <div class="col-md-4">

                <!-- Linked Lead -->
                <?php if ($lead) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot5">Linked Lead</h5>
                        <?php if (!empty($lead->company)) { ?>
                        <p style="margin-bottom:4px;"><strong><?php echo e($lead->company); ?></strong></p>
                        <?php } ?>
                        <p>
                            <a href="<?php echo admin_url('leads/index/' . $lead->id); ?>#leadid=<?php echo (int) $lead->id; ?>">
                                #<?php echo (int) $lead->id; ?> — <?php echo e($lead->name); ?>
                            </a>
                        </p>
                        <?php if (!empty($lead->email)) { ?><p><?php echo e($lead->email); ?></p><?php } ?>
                        <?php if (!empty($lead->phonenumber)) { ?><p><?php echo e($lead->phonenumber); ?></p><?php } ?>
                        <?php if (!empty($lead->source_name)) { ?><p><span class="text-muted">Source:</span> <?php echo e($lead->source_name); ?></p><?php } ?>
                        <?php if (!empty($lead->status_name)) { ?><p><span class="text-muted">Lead status:</span> <?php echo e($lead->status_name); ?></p><?php } ?>
                        <hr style="margin:8px 0;">
                        <button class="btn btn-danger btn-xs" onclick="ps_delete_profile(<?php echo (int) $lead->id; ?>)">
                            <i class="fa fa-trash"></i> Delete All ClickFuzz Web Data
                        </button>
                    </div>
                </div>
                <?php } ?>

                <!-- Site Record -->
                <?php if (!empty($site)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot5">Site Record</h5>
                        <table class="table table-condensed" style="margin-bottom:0; font-size:13px;">
                            <tr><th width="40%">Status</th><td><?php echo e($site->status ?? '—'); ?></td></tr>
                            <?php if (!empty($site->domain)) { ?>
                            <tr><th>Domain</th><td style="word-break:break-all;"><a href="https://<?php echo e($site->domain); ?>" target="_blank"><?php echo e($site->domain); ?></a></td></tr>
                            <?php } ?>
                            <?php if (!empty($site->site_token)) { ?>
                            <tr><th>Token</th><td><code style="font-size:11px;"><?php echo substr(e($site->site_token), 0, 12); ?>…</code></td></tr>
                            <?php } ?>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <!-- Agreement -->
                <?php if (!empty($agreement)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot5">Agreement</h5>
                        <table class="table table-condensed" style="margin-bottom:0; font-size:13px;">
                            <?php if (!empty($agreement->accepted_at)) { ?>
                            <tr><th width="40%">Accepted</th><td><?php echo _dt($agreement->accepted_at); ?></td></tr>
                            <?php } ?>
                            <?php if (!empty($agreement->version)) { ?>
                            <tr><th>Version</th><td><?php echo e($agreement->version); ?></td></tr>
                            <?php } ?>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <!-- Engagement stats -->
                <?php if (!empty($redesign->view_count) && (int) $redesign->view_count > 0) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot5">Prospect Engagement</h5>
                        <table class="table table-condensed" style="margin-bottom:0; font-size:13px;">
                            <tr><th width="50%">Views</th><td><?php echo (int) $redesign->view_count; ?></td></tr>
                            <?php if (!empty($redesign->first_viewed_at)) { ?><tr><th>First View</th><td><?php echo _dt($redesign->first_viewed_at); ?></td></tr><?php } ?>
                            <?php if (!empty($redesign->last_viewed_at)) { ?><tr><th>Last View</th><td><?php echo _dt($redesign->last_viewed_at); ?></td></tr><?php } ?>
                            <?php if (!empty($redesign->approved_at)) { ?><tr><th>Approved</th><td><?php echo _dt($redesign->approved_at); ?></td></tr><?php } ?>
                        </table>
                    </div>
                </div>
                <?php } ?>

            </div><!-- /.col-md-4 -->

        </div><!-- /.row -->
    </div>
</div>

<!-- Hidden form for delete_profile -->
<form id="ps_delete_profile_form" method="POST" action="" style="display:none;">
    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
</form>

<?php init_tail(); ?>
<script>
function ps_queue_generate(id) {
    var btn = event.target;
    btn.disabled = true;
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Queuing…';

    $.ajax({
        url:      admin_url + 'pitchsnap/queue_generate/' + id,
        type:     'POST',
        dataType: 'json',
        data:     {<?php echo $this->security->get_csrf_token_name(); ?>: '<?php echo $this->security->get_csrf_hash(); ?>'},
        success: function(resp) {
            if (resp.success) {
                alert_float('success', resp.message);
                setTimeout(function() { window.location.reload(); }, 1200);
            } else {
                alert_float('danger', resp.message);
                btn.disabled  = false;
                btn.innerHTML = orig;
            }
        },
        error: function() {
            alert_float('danger', 'Request failed. Please try again.');
            btn.disabled  = false;
            btn.innerHTML = orig;
        }
    });
}

function ps_modify_html(id) {
    var request = $('#ps_modify_request').val().trim();
    if (!request) { alert_float('warning', 'Please describe the changes you want.'); return; }

    var btn = $(event.target);
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Applying…');
    $('#ps_modify_status').text('');

    $.ajax({
        url:      admin_url + 'pitchsnap/modify_html/' + id,
        type:     'POST',
        dataType: 'json',
        timeout:  360000,
        data: {
            modification_request: request,
            <?php echo $this->security->get_csrf_token_name(); ?>: '<?php echo $this->security->get_csrf_hash(); ?>'
        },
        success: function(resp) {
            if (resp.success) {
                alert_float('success', resp.message);
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                alert_float('danger', resp.message);
                btn.prop('disabled', false).html('<i class="fa fa-magic"></i> Apply Changes');
            }
        },
        error: function() {
            alert_float('danger', 'Request failed or timed out.');
            btn.prop('disabled', false).html('<i class="fa fa-magic"></i> Apply Changes');
        }
    });
}

function ps_delete_profile(lead_id) {
    if (!confirm('Delete ALL ClickFuzz Web data for this lead?\n\nThis removes all versions, previews, and site records. The lead itself stays in Perfex.')) return;
    var f = document.getElementById('ps_delete_profile_form');
    f.action = admin_url + 'pitchsnap/delete_profile/' + lead_id;
    f.submit();
}
</script>
<?php
function ps_badge($status) {
    $map = [
        'new'                => 'label-default',
        'pending'            => 'label-default',
        'pending_generation' => 'label-info',
        'generating'         => 'label-info',
        'publishing'         => 'label-info',
        'modifying'          => 'label-info',
        'review_required'    => 'label-primary',
        'approved'           => 'label-success',
        'sent'               => 'label-success',
        'viewed'             => 'label-success',
        'failed'             => 'label-danger',
        'declined'           => 'label-default',
    ];
    $cls = $map[$status] ?? 'label-default';
    return '<span class="label ' . $cls . '">' . e($status) . '</span>';
}
?>
