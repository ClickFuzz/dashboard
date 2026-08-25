<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
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
