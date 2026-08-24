<?php defined('BASEPATH') or exit('No direct script access allowed'); $redesign = $website ?? null; ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">

        <!-- ── Page header ─────────────────────────────────────────────── -->
        <div class="row" style="margin-bottom:16px; border-bottom:1px solid #eee; padding-bottom:14px;">
            <div class="col-md-8">
                <a href="<?php echo admin_url('pitchsnap/websites'); ?>" class="btn btn-default btn-sm" style="margin-bottom:8px;">
                    <i class="fa fa-arrow-left"></i> All Websites
                </a>
                <h4 class="tw-font-semibold" style="margin:0 0 4px;">
                    <?php
                    if ($lead && (!empty($lead->company) || !empty($lead->name))) {
                        echo e(!empty($lead->company) ? $lead->company : $lead->name);
                    } else {
                        echo 'Website #' . (int) $redesign->id;
                    }
                    ?>
                </h4>
                <div style="font-size:13px;">
                    <span class="text-muted">Website #<?php echo (int) $redesign->id; ?></span>
                    &nbsp;<?php echo ps_badge($redesign->status); ?>
                    <?php if (!empty($redesign->is_primary)) { ?>
                    &nbsp;<span class="label label-default"><i class="fa fa-star"></i> Primary</span>
                    <?php } ?>
                    <?php if (!empty($site) && !empty($site->domain)) { ?>
                    &nbsp;<span class="text-muted"><i class="fa fa-globe"></i> <?php echo e($site->domain); ?></span>
                    <?php } ?>
                </div>
            </div>
            <div class="col-md-4 text-right" style="padding-top:28px;">
                <?php if (!empty($site) && !empty($site->domain)) { ?>
                <a href="https://<?php echo e($site->domain); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-default btn-sm">
                    <i class="fa fa-external-link"></i> View Site
                </a>
                <?php } ?>
                <?php if (!empty($redesign->preview_url)) { ?>
                <a href="<?php echo e($redesign->preview_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm">
                    <i class="fa fa-globe"></i> Open Preview
                </a>
                <?php } ?>
                <?php if (!empty($redesign->generation_result)) { ?>
                <a href="<?php echo admin_url('pitchsnap/edit_html/' . (int) $redesign->id); ?>" class="btn btn-default btn-sm">
                    <i class="fa fa-code"></i> Edit HTML
                </a>
                <?php } ?>
            </div>
        </div>

        <!-- ── Tabs ──────────────────────────────────────────────────── -->
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation" class="active">
                <a href="#tab-overview" role="tab" data-toggle="tab">Overview</a>
            </li>
            <li role="presentation">
                <a href="#tab-website" role="tab" data-toggle="tab">Website</a>
            </li>
            <li role="presentation">
                <a href="#tab-publishing" role="tab" data-toggle="tab">Domain &amp; Publishing</a>
            </li>
            <li role="presentation">
                <a href="#tab-customer" role="tab" data-toggle="tab">Customer</a>
            </li>
            <li role="presentation">
                <a href="#tab-ghl" role="tab" data-toggle="tab">GHL</a>
            </li>
            <li role="presentation">
                <a href="#tab-history" role="tab" data-toggle="tab">History</a>
            </li>
        </ul>

        <div class="tab-content" style="padding-top:20px;">

            <!-- ══════════════════════════════════════════════════════════
                 TAB 1 — OVERVIEW
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane active" id="tab-overview">

                <div class="row">

                    <!-- Website summary -->
                    <div class="col-md-6">
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Website</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr>
                                        <th width="40%">Version</th>
                                        <td>
                                            #<?php echo (int) $redesign->id; ?>
                                            <?php if (!empty($redesign->is_primary)) { ?>
                                            &nbsp;<i class="fa fa-star text-muted" title="Primary"></i>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                    <tr><th>Status</th><td><?php echo ps_badge($redesign->status); ?></td></tr>
                                    <tr>
                                        <th>Provider</th>
                                        <td><?php echo !empty($redesign->provider) ? e($redesign->provider) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Generated</th>
                                        <td><?php echo $redesign->generated_at ? _dt($redesign->generated_at) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
                                    <?php if (!empty($redesign->preview_url)) { ?>
                                    <tr>
                                        <th>Preview</th>
                                        <td>
                                            <a href="<?php echo e($redesign->preview_url); ?>" target="_blank" rel="noopener noreferrer">
                                                Open <i class="fa fa-external-link" style="font-size:11px;"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Customer summary -->
                    <div class="col-md-6">
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Customer</h5>
                                <?php if ($lead) { ?>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr>
                                        <th width="40%">Name</th>
                                        <td>
                                            <a href="<?php echo admin_url('leads/index/' . $lead->id); ?>#leadid=<?php echo (int) $lead->id; ?>">
                                                <?php echo e(!empty($lead->company) ? $lead->company : $lead->name); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php if (!empty($lead->email)) { ?>
                                    <tr><th>Email</th><td><?php echo e($lead->email); ?></td></tr>
                                    <?php } ?>
                                    <?php if (!empty($lead->status_name)) { ?>
                                    <tr><th>Lead Status</th><td><?php echo e($lead->status_name); ?></td></tr>
                                    <?php } ?>
                                    <tr>
                                        <th>Agreement</th>
                                        <td>
                                            <?php if (!empty($agreement) && !empty($agreement->accepted_at)) { ?>
                                            <span class="label label-success">Accepted</span>
                                            <span class="text-muted" style="font-size:11px; margin-left:4px;"><?php echo _dt($agreement->accepted_at); ?></span>
                                            <?php } else { ?>
                                            <span class="label label-default">Not accepted</span>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                </table>
                                <?php } else { ?>
                                <p class="text-muted">No lead linked.</p>
                                <?php } ?>
                            </div>
                        </div>
                    </div>

                </div><!-- /.row -->

                <div class="row">

                    <!-- Publishing summary -->
                    <div class="col-md-6">
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Publishing</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <?php if (!empty($site)) { ?>
                                    <tr>
                                        <th width="40%">Status</th>
                                        <td><?php echo e($site->status ?? '—'); ?></td>
                                    </tr>
                                    <?php if (!empty($site->domain)) { ?>
                                    <tr>
                                        <th>URL</th>
                                        <td style="word-break:break-all;">
                                            <a href="https://<?php echo e($site->domain); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php echo e($site->domain); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                    <?php } else { ?>
                                    <tr>
                                        <th width="40%">Status</th>
                                        <td><span class="text-muted">Not published</span></td>
                                    </tr>
                                    <?php } ?>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- GHL summary -->
                    <div class="col-md-6">
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">GoHighLevel</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr><th width="40%">Status</th><td><span class="text-muted">Not configured</span></td></tr>
                                    <tr><th>Location</th><td><span class="text-muted">—</span></td></tr>
                                    <tr><th>Last sync</th><td><span class="text-muted">—</span></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>

                </div><!-- /.row -->

                <?php
                $attention = [];
                $s_ov = $redesign->status;
                if (in_array($s_ov, ['new', 'pending'])) {
                    $attention[] = ['warning', 'Website has not been generated yet.'];
                } elseif ($s_ov === 'failed') {
                    $msg = 'Generation failed.';
                    if (!empty($redesign->generation_error)) {
                        $msg .= ' Error: ' . e($redesign->generation_error);
                    }
                    $msg .= ' Retry from the Website tab.';
                    $attention[] = ['danger', $msg];
                } elseif ($s_ov === 'review_required') {
                    $attention[] = ['info', 'Website is ready for review — approve or regenerate from the Website tab.'];
                }
                if (empty($site) || empty($site->domain)) {
                    $attention[] = ['warning', 'Site has not been published yet.'];
                }
                if (!empty($site) && (empty($agreement) || empty($agreement->accepted_at))) {
                    $attention[] = ['warning', 'Agreement not yet accepted by customer.'];
                }
                ?>
                <?php if (!empty($attention)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Attention</h5>
                        <?php foreach ($attention as $item) { ?>
                        <div class="alert alert-<?php echo $item[0]; ?>" style="margin-bottom:6px; padding:8px 12px; font-size:13px;">
                            <?php if ($item[0] === 'danger') { ?>
                            <i class="fa fa-times-circle"></i>
                            <?php } elseif ($item[0] === 'info') { ?>
                            <i class="fa fa-info-circle"></i>
                            <?php } else { ?>
                            <i class="fa fa-exclamation-triangle"></i>
                            <?php } ?>
                            <?php echo $item[1]; ?>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                <?php } ?>

            </div><!-- #tab-overview -->


            <!-- ══════════════════════════════════════════════════════════
                 TAB 2 — WEBSITE
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-website">

                <!-- Current Website + Generation controls -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Current Website</h5>

                        <table class="table table-bordered table-condensed mbot15" style="max-width:600px;">
                            <tbody>
                                <tr>
                                    <th width="35%">Version</th>
                                    <td>
                                        #<?php echo (int) $redesign->id; ?>
                                        <?php if (!empty($redesign->is_primary)) { ?>&nbsp;<i class="fa fa-star text-muted" title="Primary"></i><?php } ?>
                                        <?php if (!empty($redesign->parent_redesign_id)) { ?>
                                        &nbsp;<small class="text-muted">(regenerated from <a href="<?php echo admin_url('pitchsnap/detail/' . (int) $redesign->parent_redesign_id); ?>">#<?php echo (int) $redesign->parent_redesign_id; ?></a>)</small>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Status</th>
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
                                    <th>Generated</th>
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
                           class="btn btn-default mright5"
                           onclick="return confirm('Create a new version from this one?');">
                            <i class="fa fa-refresh"></i> Regenerate
                        </a>
                        <?php if (!empty($redesign->generation_result)) { ?>
                        <a href="<?php echo admin_url('pitchsnap/edit_html/' . (int) $redesign->id); ?>" class="btn btn-default mright5">
                            <i class="fa fa-code"></i> Edit HTML
                        </a>
                        <button class="btn btn-default" onclick="$('#ps_modify_panel').toggle();">
                            <i class="fa fa-magic"></i> AI Modify
                        </button>
                        <?php } ?>

                        <?php } elseif (in_array($s, ['approved', 'sent', 'viewed'])) { ?>
                        <a href="<?php echo admin_url('pitchsnap/regenerate/' . (int) $redesign->id); ?>"
                           class="btn btn-default mright5"
                           onclick="return confirm('Create a new version from this one?');">
                            <i class="fa fa-refresh"></i> Regenerate
                        </a>
                        <?php if (!empty($redesign->generation_result)) { ?>
                        <a href="<?php echo admin_url('pitchsnap/edit_html/' . (int) $redesign->id); ?>" class="btn btn-default mright5">
                            <i class="fa fa-code"></i> Edit HTML
                        </a>
                        <button class="btn btn-default" onclick="$('#ps_modify_panel').toggle();">
                            <i class="fa fa-magic"></i> AI Modify
                        </button>
                        <?php } ?>

                        <?php } elseif ($s === 'failed') { ?>
                        <button class="btn btn-primary mright5" onclick="ps_queue_generate(<?php echo (int) $redesign->id; ?>)">
                            <i class="fa fa-refresh"></i> Retry Generation
                        </button>
                        <a href="<?php echo admin_url('pitchsnap/retry_anthropic/' . (int) $redesign->id); ?>"
                           class="btn btn-default mright5">
                            <i class="fa fa-cloud"></i> Retry with Anthropic
                        </a>
                        <a href="<?php echo admin_url('pitchsnap/regenerate/' . (int) $redesign->id); ?>"
                           class="btn btn-default mright5"
                           onclick="return confirm('Create a new version from this one?');">
                            <i class="fa fa-copy"></i> New Version
                        </a>
                        <?php if (!empty($redesign->generation_result)) { ?>
                        <a href="<?php echo admin_url('pitchsnap/edit_html/' . (int) $redesign->id); ?>" class="btn btn-default mright5">
                            <i class="fa fa-code"></i> Edit HTML
                        </a>
                        <button class="btn btn-default" onclick="$('#ps_modify_panel').toggle();">
                            <i class="fa fa-magic"></i> AI Modify
                        </button>
                        <?php } ?>
                        <?php } ?>

                        <!-- AI Modify panel -->
                        <?php if (!empty($redesign->generation_result)) { ?>
                        <div id="ps_modify_panel" style="display:none; margin-top:12px; padding-top:12px; border-top:1px solid #eee;">
                            <p class="text-muted" style="font-size:12px; margin-bottom:6px;">Describe the changes to apply. The AI will edit only what you specify and return the full updated HTML.</p>
                            <textarea id="ps_modify_request" class="form-control" rows="3"
                                      placeholder="e.g. Change the hero headline to 'Trusted Local Plumbers'…"></textarea>
                            <button class="btn btn-primary btn-sm mtop10" onclick="ps_modify_html(<?php echo (int) $redesign->id; ?>)">
                                <i class="fa fa-magic"></i> Apply Changes
                            </button>
                            <span id="ps_modify_status" class="text-muted" style="font-size:12px; margin-left:10px;"></span>
                        </div>
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

                <!-- Preview -->
                <?php if (!empty($redesign->preview_url)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between">
                            <h5 class="tw-font-semibold" style="margin:0;">Preview</h5>
                            <div>
                                <a href="<?php echo e($redesign->preview_url); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm mright5">
                                    <i class="fa fa-globe"></i> Open Preview
                                </a>
                                <form method="POST" action="<?php echo admin_url('pitchsnap/delete_preview/' . (int) $redesign->id); ?>" style="display:inline;">
                                    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Delete preview files? The website record will be preserved.');">
                                        <i class="fa fa-trash"></i> Delete Preview
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } ?>

                <!-- Version History -->
                <?php if (!empty($versions)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between mbot10">
                            <h5 class="tw-font-semibold" style="margin:0;">Version History</h5>
                            <button class="btn btn-danger btn-xs" onclick="ps_bulk_delete()">
                                <i class="fa fa-trash"></i> Delete Selected
                            </button>
                        </div>
                        <table class="table table-bordered table-condensed" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th width="4%"><input type="checkbox" id="ps-select-all" onclick="ps_select_all(this)"></th>
                                    <th width="8%">#</th>
                                    <th width="20%">Status</th>
                                    <th width="26%">Created</th>
                                    <th width="12%">Provider</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($versions as $v) { ?>
                                <tr<?php echo ($v->id == $redesign->id) ? ' class="active"' : ''; ?>>
                                    <td>
                                        <?php if (empty($v->is_primary)) { ?>
                                        <input type="checkbox" class="ps-version-cb" value="<?php echo (int) $v->id; ?>">
                                        <?php } ?>
                                    </td>
                                    <td>#<?php echo (int) $v->id; ?></td>
                                    <td><?php echo ps_badge($v->status); ?></td>
                                    <td style="font-size:12px;"><?php echo _dt($v->dateadded); ?></td>
                                    <td style="font-size:12px;"><?php echo !empty($v->provider) ? e($v->provider) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="text-right" style="white-space:nowrap;">
                                        <?php if (!empty($v->is_primary)) { ?>
                                        <span class="label label-default"><i class="fa fa-star"></i> Primary</span>
                                        <?php } else { ?>
                                        <?php if ($v->id != $redesign->id) { ?>
                                        <a href="<?php echo admin_url('pitchsnap/detail/' . (int) $v->id); ?>" class="btn btn-default btn-xs">View</a>
                                        <?php } ?>
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

                <!-- Prospect Engagement -->
                <?php if (!empty($redesign->view_count) && (int) $redesign->view_count > 0) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Prospect Engagement</h5>
                        <table class="table table-condensed" style="margin-bottom:0; font-size:13px; max-width:400px;">
                            <tr><th width="40%">Views</th><td><?php echo (int) $redesign->view_count; ?></td></tr>
                            <?php if (!empty($redesign->first_viewed_at)) { ?><tr><th>First View</th><td><?php echo _dt($redesign->first_viewed_at); ?></td></tr><?php } ?>
                            <?php if (!empty($redesign->last_viewed_at)) { ?><tr><th>Last View</th><td><?php echo _dt($redesign->last_viewed_at); ?></td></tr><?php } ?>
                            <?php if (!empty($redesign->approved_at)) { ?><tr><th>Approved</th><td><?php echo _dt($redesign->approved_at); ?></td></tr><?php } ?>
                        </table>
                    </div>
                </div>
                <?php } ?>

            </div><!-- #tab-website -->


            <!-- ══════════════════════════════════════════════════════════
                 TAB 3 — DOMAIN & PUBLISHING
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-publishing">

                <!-- Publishing -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Publishing</h5>
                        <table class="table table-bordered table-condensed mbot15" style="max-width:520px;">
                            <tbody>
                                <?php if (!empty($site)) { ?>
                                <tr>
                                    <th width="35%">Status</th>
                                    <td><?php echo e($site->status ?? '—'); ?></td>
                                </tr>
                                <?php if (!empty($site->domain)) { ?>
                                <tr>
                                    <th>URL</th>
                                    <td style="word-break:break-all;">
                                        <a href="https://<?php echo e($site->domain); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo e($site->domain); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php } ?>
                                <?php if (!empty($site->site_token)) { ?>
                                <tr>
                                    <th>Token</th>
                                    <td><code style="font-size:11px;"><?php echo substr(e($site->site_token), 0, 12); ?>…</code></td>
                                </tr>
                                <?php } ?>
                                <?php } else { ?>
                                <tr>
                                    <th width="35%">Status</th>
                                    <td><span class="text-muted">Not published</span></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>

                        <?php if (!empty($site)) { ?>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/publish_site/' . (int) $redesign->id); ?>" style="display:inline;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-primary btn-sm"
                                    onclick="return confirm('Publish this site to its permanent URL?');">
                                <i class="fa fa-upload"></i> Publish Site
                            </button>
                        </form>
                        <?php } elseif (!empty($redesign->preview_url) || !empty($redesign->generation_result)) { ?>
                        <p class="text-muted" style="font-size:13px; margin:0;">
                            <i class="fa fa-info-circle"></i> No site record exists yet. Trigger a generation first so ClickFuzz Web can create the site record.
                        </p>
                        <?php } else { ?>
                        <p class="text-muted" style="font-size:13px; margin:0;">
                            <i class="fa fa-info-circle"></i> Generate the website first before publishing.
                        </p>
                        <?php } ?>
                    </div>
                </div>

                <!-- Custom Domain placeholder -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Custom Domain</h5>
                        <table class="table table-bordered table-condensed mbot15" style="max-width:520px;">
                            <tbody>
                                <tr><th width="35%">Custom domain</th><td><span class="text-muted">Not configured</span></td></tr>
                                <tr><th>DNS</th><td><span class="text-muted">Not configured</span></td></tr>
                                <tr><th>SSL</th><td><span class="text-muted">Not configured</span></td></tr>
                            </tbody>
                        </table>
                        <p class="text-muted" style="font-size:12px; margin:0;">
                            <i class="fa fa-clock-o"></i> Custom domain provisioning is coming in a future update.
                        </p>
                    </div>
                </div>

            </div><!-- #tab-publishing -->


            <!-- ══════════════════════════════════════════════════════════
                 TAB 4 — CUSTOMER
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-customer">

                <div class="row">

                    <div class="col-md-6">

                        <!-- Business -->
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Business</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr>
                                        <th width="40%">Original Website</th>
                                        <td>
                                            <?php if (!empty($redesign->original_url)) { ?>
                                            <a href="<?php echo e($redesign->original_url); ?>" target="_blank" rel="noopener noreferrer" style="word-break:break-all;">
                                                <?php echo e($redesign->original_url); ?>
                                            </a>
                                            <?php } else { ?><span class="text-muted">Not provided</span><?php } ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Business Type</th>
                                        <td><?php echo !empty($redesign->vertical) ? e($redesign->vertical) : '<span class="text-muted">—</span>'; ?></td>
                                    </tr>
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
                                    <tr>
                                        <th>Created</th>
                                        <td><?php echo _dt($redesign->dateadded); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <!-- Contact -->
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Contact</h5>
                                <?php if ($lead) { ?>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <tr>
                                        <th width="35%">Name</th>
                                        <td><?php echo e($lead->name); ?></td>
                                    </tr>
                                    <?php if (!empty($lead->company)) { ?>
                                    <tr>
                                        <th>Company</th>
                                        <td><?php echo e($lead->company); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php if (!empty($lead->email)) { ?>
                                    <tr>
                                        <th>Email</th>
                                        <td><a href="mailto:<?php echo e($lead->email); ?>"><?php echo e($lead->email); ?></a></td>
                                    </tr>
                                    <?php } ?>
                                    <?php if (!empty($lead->phonenumber)) { ?>
                                    <tr>
                                        <th>Phone</th>
                                        <td><?php echo e($lead->phonenumber); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php if (!empty($lead->source_name)) { ?>
                                    <tr>
                                        <th>Source</th>
                                        <td><?php echo e($lead->source_name); ?></td>
                                    </tr>
                                    <?php } ?>
                                </table>
                                <?php } else { ?>
                                <p class="text-muted">No lead linked.</p>
                                <?php } ?>
                            </div>
                        </div>

                        <!-- Account -->
                        <div class="panel_s">
                            <div class="panel-body">
                                <h5 class="tw-font-semibold mbot10">Account</h5>
                                <table class="table table-condensed" style="margin:0; font-size:13px;">
                                    <?php if ($lead) { ?>
                                    <tr>
                                        <th width="40%">Lead</th>
                                        <td>
                                            <a href="<?php echo admin_url('leads/index/' . $lead->id); ?>#leadid=<?php echo (int) $lead->id; ?>">
                                                #<?php echo (int) $lead->id; ?> — <?php echo e($lead->name); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php if (!empty($lead->status_name)) { ?>
                                    <tr>
                                        <th>Lead Status</th>
                                        <td><?php echo e($lead->status_name); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php } ?>
                                    <?php if (!empty($site)) { ?>
                                    <tr>
                                        <th>Site Status</th>
                                        <td><?php echo e($site->status ?? '—'); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <tr>
                                        <th>Agreement</th>
                                        <td>
                                            <?php if (!empty($agreement) && !empty($agreement->accepted_at)) { ?>
                                            <span class="label label-success">Accepted</span>
                                            <?php echo _dt($agreement->accepted_at); ?>
                                            <?php if (!empty($agreement->version)) { ?>
                                            <span class="text-muted" style="font-size:11px;">(v<?php echo e($agreement->version); ?>)</span>
                                            <?php } ?>
                                            <?php } else { ?>
                                            <span class="label label-default">Not accepted</span>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                    </div>

                </div><!-- /.row -->

                <!-- Danger Zone -->
                <?php if ($lead) { ?>
                <div class="panel_s" style="border-top:3px solid #e74c3c;">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold text-danger mbot5">Danger Zone</h5>
                        <p class="text-muted" style="font-size:12px; margin-bottom:10px;">
                            Permanently removes all ClickFuzz Web data for this customer — all website versions, previews, and site records.
                            The lead record itself is preserved in Perfex.
                        </p>
                        <button class="btn btn-danger btn-sm" onclick="ps_delete_profile(<?php echo (int) $lead->id; ?>)">
                            <i class="fa fa-trash"></i> Delete All ClickFuzz Web Data
                        </button>
                    </div>
                </div>
                <?php } ?>

            </div><!-- #tab-customer -->


            <!-- ══════════════════════════════════════════════════════════
                 TAB 5 — GHL
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-ghl">
                <div class="panel_s" style="max-width:520px;">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">GoHighLevel</h5>
                        <table class="table table-condensed" style="margin:0; font-size:13px;">
                            <tr><th width="40%">Status</th><td><span class="text-muted">Not configured</span></td></tr>
                            <tr><th>Location</th><td><span class="text-muted">—</span></td></tr>
                            <tr><th>Last sync</th><td><span class="text-muted">—</span></td></tr>
                        </table>
                        <p class="text-muted" style="font-size:12px; margin-top:10px; margin-bottom:0;">
                            <i class="fa fa-info-circle"></i> GoHighLevel integration will be configured separately.
                        </p>
                    </div>
                </div>
            </div><!-- #tab-ghl -->


            <!-- ══════════════════════════════════════════════════════════
                 TAB 6 — HISTORY
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-history">

                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Sales Chat</h5>
                        <?php if (!empty($conversations)) { ?>
                        <table class="table table-bordered table-condensed" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th width="30%">Date</th>
                                    <th>Response</th>
                                </tr>
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
                                <td style="white-space:nowrap; vertical-align:top; font-size:12px;"><?php echo _dt($conv['created_at']); ?></td>
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
                        <?php } else { ?>
                        <p class="text-muted" style="margin:0;">No conversation history yet.</p>
                        <?php } ?>
                    </div>
                </div>

            </div><!-- #tab-history -->

        </div><!-- /.tab-content -->
    </div><!-- /.content -->
</div><!-- #wrapper -->

<!-- Hidden form for delete_profile -->
<form id="ps_delete_profile_form" method="POST" action="" style="display:none;">
    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
</form>
<!-- Hidden form for bulk version delete -->
<form id="ps_bulk_delete_form" method="POST" action="<?php echo admin_url('pitchsnap/delete_versions'); ?>" style="display:none;">
    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
    <input type="hidden" name="redirect_id" value="<?php echo (int) $redesign->id; ?>">
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

function ps_select_all(master) {
    document.querySelectorAll('.ps-version-cb').forEach(function(cb) {
        cb.checked = master.checked;
    });
}

function ps_bulk_delete() {
    var cbs = document.querySelectorAll('.ps-version-cb:checked');
    if (!cbs.length) { alert_float('warning', 'Select at least one version to delete.'); return; }
    if (!confirm('Delete ' + cbs.length + ' version(s)? This cannot be undone.')) return;
    var f = document.getElementById('ps_bulk_delete_form');
    f.querySelectorAll('input[name="ids[]"]').forEach(function(el) { el.remove(); });
    cbs.forEach(function(cb) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'ids[]';
        inp.value = cb.value;
        f.appendChild(inp);
    });
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
