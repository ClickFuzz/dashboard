<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
if (!function_exists('ps_badge')) {
    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_media_helper.php';
}
?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB — PAGES
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-pages">

                <!-- ── Homepage ──────────────────────────────────────────── -->

                <!-- Current Website + Generation controls -->
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot10">Current Design</h5>

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
                                    <td><?php echo $is_published ? ps_badge('published') : ps_badge($redesign->status); ?></td>
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
                        <?php if (!$is_published) { ?>
                        <form method="POST" action="<?php echo admin_url('pitchsnap/approve_design/' . (int) $redesign->id); ?>" style="display:inline;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <button type="submit" class="btn btn-success mright5"
                                    onclick="return confirm('Send this design to the client for approval?');">
                                <i class="fa fa-paper-plane"></i> Send for Approval
                            </button>
                        </form>
                        <?php } ?>
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
                <!-- NOTE: final placement deferred to Phase 3 Overview cleanup.
                     This panel belongs to the $redesign record (prospect preview engagement),
                     not to the internal page tree. Moving it here temporarily. -->
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


                <!-- ── Internal Pages (published sites only) ──────────────── -->
                <?php if ($is_published) { ?>

                <?php
                // Build page tree: top-level pages first, then children ordered
                $_all_pages   = $pages ?? [];
                $_top_pages   = [];
                $_child_map   = [];
                foreach ($_all_pages as $_p) {
                    if (empty($_p->parent_page_id)) {
                        $_top_pages[] = $_p;
                    } else {
                        $_child_map[$_p->parent_page_id][] = $_p;
                    }
                }
                // Flatten into ordered list with depth
                $_flat = [];
                $_add_row = function($_page, $_depth) use (&$_add_row, &$_flat, &$_child_map) {
                    $_flat[] = ['page' => $_page, 'depth' => $_depth];
                    if (isset($_child_map[$_page->id])) {
                        foreach ($_child_map[$_page->id] as $_child) {
                            $_add_row($_child, $_depth + 1);
                        }
                    }
                };
                foreach ($_top_pages as $_tp) { $_add_row($_tp, 0); }
                // Orphaned pages (parent trashed)
                foreach ($_all_pages as $_p) {
                    if (!empty($_p->parent_page_id) && !isset($_child_map[$_p->parent_page_id])) {
                        $already = false;
                        foreach ($_flat as $_fr) { if ($_fr['page']->id == $_p->id) { $already = true; break; } }
                        if (!$already) { $_flat[] = ['page' => $_p, 'depth' => 0]; }
                    }
                }
                ?>

                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between mbot15">
                            <h5 class="tw-font-semibold" style="margin:0;">Generate Pages</h5>
                            <button class="btn btn-primary btn-sm"
                                    data-toggle="modal" data-target="#ps-add-page-modal">
                                <i class="fa fa-plus"></i> Add Page
                            </button>
                        </div>

                        <?php if (empty($_flat)) { ?>
                        <p class="text-muted" style="margin-bottom:0;">
                            No pages yet. Click <strong>Add Page</strong> to create your first internal page.
                        </p>
                        <?php } else { ?>
                        <table class="table table-bordered table-condensed" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th>Page</th>
                                    <th width="12%">Type</th>
                                    <th width="13%">Status</th>
                                    <th width="15%">Generation</th>
                                    <th width="18%">Slug</th>
                                    <th width="10%"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($_flat as $_row) {
                                $_pg    = $_row['page'];
                                $_depth = $_row['depth'];
                                $_trashed = $_pg->status === 'trash';
                                $_indent  = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $_depth);
                                $_type_labels = [
                                    'homepage' => 'Homepage',
                                    'about' => 'About', 'service' => 'Service',
                                    'service_area' => 'Service Area', 'contact' => 'Contact',
                                    'gallery' => 'Gallery', 'financing' => 'Financing',
                                    'faq' => 'FAQ', 'custom' => 'Custom',
                                ];
                                $_gen_badges = [
                                    'not_generated' => '<span class="label label-default">Not Generated</span>',
                                    'generating'    => '<span class="label label-warning"><i class="fa fa-spinner fa-spin"></i> Generating</span>',
                                    'generated'     => '<span class="label label-success">Generated</span>',
                                    'failed'        => '<span class="label label-danger">Failed</span>',
                                ];
                            ?>
                            <tr<?php echo $_trashed ? ' class="text-muted" style="opacity:.6;"' : ''; ?>>
                                <td>
                                    <?php echo $_indent; ?>
                                    <?php if ($_depth > 0) { ?><i class="fa fa-level-up fa-rotate-90 text-muted" style="font-size:10px;"></i>&nbsp;<?php } ?>
                                    <?php echo $_trashed ? '<s>' . e($_pg->title) . '</s>' : e($_pg->title); ?>
                                    <?php if ($_trashed) { ?><small class="text-muted"> (Trash)</small><?php } ?>
                                </td>
                                <td style="font-size:12px;"><?php echo e($_type_labels[$_pg->page_type] ?? ucfirst($_pg->page_type)); ?></td>
                                <td style="font-size:12px;"><?php echo ps_badge($_pg->status); ?></td>
                                <td style="font-size:12px;"><?php echo $_gen_badges[$_pg->generation_status] ?? e($_pg->generation_status); ?></td>
                                <td style="font-size:11px; font-family:monospace;"><?php echo e($_pg->slug); ?></td>
                                <td class="text-right" style="white-space:nowrap;">
                                    <?php if ($_trashed) { ?>
                                    <form method="POST" action="<?php echo admin_url('pitchsnap/page_restore/' . (int) $_pg->id); ?>" style="display:inline;">
                                        <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                        <button type="submit" class="btn btn-default btn-xs">Restore</button>
                                    </form>
                                    <?php } else { ?>
                                    <a href="<?php echo admin_url('pitchsnap/page_edit/' . (int) $_pg->id); ?>" class="btn btn-default btn-xs">Configure</a>
                                    <form method="POST" action="<?php echo admin_url('pitchsnap/page_trash/' . (int) $_pg->id); ?>" style="display:inline;">
                                        <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                        <button type="submit" class="btn btn-danger btn-xs"
                                                onclick="return confirm('Move this page to Trash?');">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                    <?php } ?>
                                </td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        <?php } ?>

                    </div>
                </div>

                <!-- Add Page modal — 3-step wizard -->
                <div class="modal fade" id="ps-add-page-modal" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <form method="POST" id="ps-add-page-form"
                                  action="<?php echo admin_url('pitchsnap/page_add/' . (int) $site->id); ?>"
                                  enctype="multipart/form-data">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">

                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    <h4 class="modal-title">Add Page</h4>
                                </div>

                                <!-- Progress bar -->
                                <div id="ps-wz-progress" style="padding:0 15px; margin-top:12px;">
                                    <div style="display:flex; gap:6px; align-items:center; font-size:12px; color:#888;">
                                        <span id="ps-wz-step-lbl-1" style="font-weight:600; color:#337ab7;">1. Details</span>
                                        <span style="flex:1; height:2px; background:#ddd; border-radius:2px; position:relative;">
                                            <span id="ps-wz-bar-1" style="position:absolute;left:0;top:0;height:100%;width:0;background:#337ab7;transition:width .3s;border-radius:2px;"></span>
                                        </span>
                                        <span id="ps-wz-step-lbl-2" style="">2. Keywords</span>
                                        <span style="flex:1; height:2px; background:#ddd; border-radius:2px; position:relative;">
                                            <span id="ps-wz-bar-2" style="position:absolute;left:0;top:0;height:100%;width:0;background:#337ab7;transition:width .3s;border-radius:2px;"></span>
                                        </span>
                                        <span id="ps-wz-step-lbl-3" style="">3. Media</span>
                                    </div>
                                </div>

                                <!-- Step 1: Page details -->
                                <div id="ps-wz-step-1" class="modal-body">
                                    <div class="form-group">
                                        <label>Page Name <span class="text-danger">*</span></label>
                                        <input type="text" name="title" id="ps-new-page-title" class="form-control"
                                               placeholder="e.g. AC Repair">
                                    </div>
                                    <div class="form-group">
                                        <label>Page Type <span class="text-danger">*</span></label>
                                        <select name="page_type" id="ps-new-page-type" class="form-control">
                                            <option value="">— Select type —</option>
                                            <option value="about">About</option>
                                            <option value="service">Service</option>
                                            <option value="service_area">Service Area</option>
                                            <option value="contact">Contact</option>
                                            <option value="gallery">Gallery</option>
                                            <option value="financing">Financing</option>
                                            <option value="faq">FAQ</option>
                                            <option value="custom">Custom</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Parent Page <small class="text-muted">(optional)</small></label>
                                        <select name="parent_page_id" class="form-control">
                                            <option value="">— None (top-level) —</option>
                                            <?php foreach ($_all_pages as $_ap) {
                                                if ($_ap->status === 'trash') { continue; }
                                            ?>
                                            <option value="<?php echo (int)$_ap->id; ?>"><?php echo e($_ap->title); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Slug <span class="text-danger">*</span></label>
                                        <input type="text" name="slug" id="ps-new-page-slug" class="form-control"
                                               placeholder="e.g. ac-repair" pattern="[a-z0-9\-]+"
                                               title="Lowercase letters, numbers, and hyphens only">
                                        <small class="text-muted">Lowercase, hyphens only. Auto-suggested from page name.</small>
                                    </div>
                                </div>

                                <!-- Step 2: Keywords -->
                                <div id="ps-wz-step-2" class="modal-body" style="display:none;">
                                    <div class="form-group">
                                        <label>Main Keyword</label>
                                        <input type="text" name="primary_keyword" class="form-control"
                                               placeholder="e.g. AC repair Denver">
                                        <small class="text-muted">Main keyword this page should rank for.</small>
                                    </div>
                                    <div class="form-group">
                                        <label>Supporting Keywords</label>
                                        <textarea name="supporting_keywords" class="form-control" rows="2"
                                                  placeholder="e.g. air conditioner repair, HVAC service"></textarea>
                                        <small class="text-muted">One per line or comma-separated.</small>
                                    </div>
                                    <div class="form-group">
                                        <label>What is this page about?</label>
                                        <textarea name="instructions" class="form-control" rows="3"
                                                  placeholder="e.g. This page targets homeowners needing emergency AC repair in Denver. Emphasize same-day service and financing options."></textarea>
                                        <small class="text-muted">Guides the AI when generating this page.</small>
                                    </div>
                                </div>

                                <!-- Step 3: Media + Video -->
                                <div id="ps-wz-step-3" class="modal-body" style="display:none; padding:0;">

                                    <!-- Upload sub-panel (shown when Upload Media is clicked) -->
                                    <div id="ps-wz-upload-panel" style="display:none; padding:15px;">
                                        <h5 style="margin-top:0; margin-bottom:14px; font-weight:600;">Upload Media</h5>
                                        <div class="form-group">
                                            <label>File <span class="text-danger">*</span></label>
                                            <input type="file" id="ps-media-file-input" accept="image/*" class="form-control">
                                        </div>
                                        <div class="form-group">
                                            <label>Alt Text</label>
                                            <input type="text" id="ps-upload-alt" class="form-control"
                                                   placeholder="e.g. AC repair technician on roof">
                                            <small class="text-muted">Becomes the filename and image title.</small>
                                        </div>
                                        <div class="form-group">
                                            <label>Category</label>
                                            <select id="ps-upload-category" class="form-control">
                                                <?php
                                                $_wz_cats = ['general'=>'General','logo'=>'Logo','team'=>'Team','project'=>'Project',
                                                             'equipment'=>'Equipment','award'=>'Award','certification'=>'Certification','before_after'=>'Before/After'];
                                                foreach ($_wz_cats as $_ck => $_cv) { ?>
                                                <option value="<?php echo $_ck; ?>"><?php echo $_cv; ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <small class="text-danger" id="ps-upload-status" style="min-height:18px; display:block;"></small>
                                    </div>

                                    <!-- Main panel -->
                                    <div id="ps-wz-main-panel" style="padding:15px;">
                                        <div class="form-group" style="margin-bottom:10px;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                                <label style="margin-bottom:0;">Images <small class="text-muted">(click to select; numbered in order)</small></label>
                                                <button type="button" class="btn btn-default btn-xs" onclick="psWzShowUpload()">
                                                    <i class="fa fa-upload"></i> Upload Media
                                                </button>
                                            </div>
                                            <div id="ps-media-scroll" style="height:190px; overflow-y:auto; border:1px solid #ddd; border-radius:4px; padding:8px; background:#fafafa;">
                                                <div id="ps-media-empty" style="height:174px; display:<?php echo empty($site_media) ? 'flex' : 'none'; ?>; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:#aaa;">
                                                    <span style="font-size:13px;">No media uploaded yet.</span>
                                                    <button type="button" class="btn btn-default btn-sm" onclick="psWzShowUpload()">
                                                        <i class="fa fa-upload"></i> Upload Media
                                                    </button>
                                                </div>
                                                <div id="ps-media-grid" style="display:<?php echo empty($site_media) ? 'none' : 'flex'; ?>; flex-wrap:wrap; gap:8px;">
                                                    <?php foreach ($site_media as $_sm) { ?>
                                                    <div class="ps-media-thumb"
                                                         data-id="<?php echo (int)$_sm->id; ?>"
                                                         data-alt="<?php echo e($_sm->alt_text ?: $_sm->original_filename); ?>"
                                                         style="width:80px; height:80px; border-radius:4px; overflow:hidden; cursor:pointer; position:relative; border:2px solid transparent; flex-shrink:0;">
                                                        <img src="<?php echo base_url('uploads/pitchsnap/media/' . (int)$site->id . '/' . rawurlencode($_sm->filename)); ?>"
                                                             alt="<?php echo e($_sm->alt_text ?: ''); ?>"
                                                             style="width:100%; height:100%; object-fit:cover;">
                                                        <span class="ps-media-badge"
                                                              style="display:none; position:absolute; bottom:3px; right:3px; background:#337ab7; color:#fff; border-radius:50%; width:18px; height:18px; font-size:10px; font-weight:700; line-height:18px; text-align:center;"></span>
                                                    </div>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                            <div id="ps-media-hidden-inputs"></div>
                                        </div>

                                        <div class="form-group">
                                            <label>Video URL <small class="text-muted">(optional)</small></label>
                                            <input type="text" name="video_url" class="form-control"
                                                   placeholder="e.g. https://www.youtube.com/watch?v=…">
                                        </div>

                                        <div class="form-group">
                                            <label>Page Content <small class="text-muted">(optional)</small></label>
                                            <textarea name="content_notes" class="form-control" rows="3"
                                                      placeholder="e.g. Hero uses Image 1. Include a list of services and a CTA for free estimates."></textarea>
                                            <small class="text-muted">Reference images by number (Image 1, Image 2…).</small>
                                        </div>
                                    </div>

                                </div>

                                <div class="modal-footer" style="padding:10px 15px;">
                                    <!-- Wizard nav (default) -->
                                    <div id="ps-wz-footer-nav" style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                                        <div>
                                            <button type="button" class="btn btn-default" id="ps-wz-back" style="display:none;" onclick="psWzBack()">
                                                <i class="fa fa-arrow-left"></i> Back
                                            </button>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                                            <button type="button" class="btn btn-primary" id="ps-wz-next" onclick="psWzNext()">
                                                Continue <i class="fa fa-arrow-right"></i>
                                            </button>
                                            <button type="submit" class="btn btn-primary" id="ps-wz-submit" style="display:none;">
                                                <i class="fa fa-check"></i> Create Page
                                            </button>
                                        </div>
                                    </div>
                                    <!-- Upload mode -->
                                    <div id="ps-wz-footer-upload" style="display:none; justify-content:flex-end; align-items:center; width:100%; gap:6px;">
                                        <button type="button" class="btn btn-default" onclick="psWzHideUpload()">Cancel</button>
                                        <button type="button" class="btn btn-primary" id="ps-upload-submit-btn" onclick="psWzDoUpload()">
                                            <i class="fa fa-upload"></i> Upload
                                        </button>
                                    </div>
                                </div>

                            </form>
                        </div>
                    </div>
                </div>

                <script>
                (function() {
                    var _step = 1;
                    var _selected = []; // ordered array of media IDs (strings)
                    var _uploadSiteId = <?php echo (int)$site->id; ?>;
                    var _uploadUrl = '<?php echo admin_url('pitchsnap/page_media_upload/' . (int)$site->id); ?>';
                    var _csrfName = '<?php echo $this->security->get_csrf_token_name(); ?>';
                    var _csrfHash = '<?php echo $this->security->get_csrf_hash(); ?>';

                    function psWzRefreshProgress() {
                        var labels = [
                            document.getElementById('ps-wz-step-lbl-1'),
                            document.getElementById('ps-wz-step-lbl-2'),
                            document.getElementById('ps-wz-step-lbl-3'),
                        ];
                        var bars = [
                            document.getElementById('ps-wz-bar-1'),
                            document.getElementById('ps-wz-bar-2'),
                        ];
                        labels.forEach(function(el, i) {
                            el.style.fontWeight = (i + 1 === _step) ? '700' : '400';
                            el.style.color      = (i + 1 <= _step)  ? '#337ab7' : '#aaa';
                        });
                        bars[0].style.width = _step >= 2 ? '100%' : '0';
                        bars[1].style.width = _step >= 3 ? '100%' : '0';
                    }

                    function psWzShowStep(n) {
                        for (var i = 1; i <= 3; i++) {
                            document.getElementById('ps-wz-step-' + i).style.display = (i === n) ? '' : 'none';
                        }
                        document.getElementById('ps-wz-back').style.display   = n > 1 ? '' : 'none';
                        document.getElementById('ps-wz-next').style.display   = n < 3 ? '' : 'none';
                        document.getElementById('ps-wz-submit').style.display = n === 3 ? '' : 'none';
                        _step = n;
                        psWzRefreshProgress();
                    }

                    window.psWzNext = function() {
                        if (_step === 1) {
                            var title = document.getElementById('ps-new-page-title').value.trim();
                            var type  = document.getElementById('ps-new-page-type').value;
                            var slug  = document.getElementById('ps-new-page-slug').value.trim();
                            if (!title) { alert('Page name is required.'); return; }
                            if (!type)  { alert('Page type is required.'); return; }
                            if (!slug)  { alert('Slug is required.'); return; }
                        }
                        psWzShowStep(_step + 1);
                    };

                    window.psWzBack = function() {
                        psWzShowStep(_step - 1);
                    };

                    // Auto-suggest slug from page name
                    document.getElementById('ps-new-page-title').addEventListener('input', function() {
                        var slug = this.value.toLowerCase()
                            .replace(/[^a-z0-9\s\-]/g, '')
                            .trim().replace(/\s+/g, '-')
                            .replace(/-+/g, '-');
                        document.getElementById('ps-new-page-slug').value = slug;
                    });

                    // Reset wizard when modal closes
                    document.getElementById('ps-add-page-modal').addEventListener('hidden.bs.modal', psWzReset);
                    document.getElementById('ps-add-page-modal').addEventListener('hidden', psWzReset);

                    function psWzReset() {
                        _selected = [];
                        psWzRefreshThumbs();
                        psWzHideUpload();
                        psWzShowStep(1);
                        document.getElementById('ps-add-page-form').reset();
                        document.getElementById('ps-upload-status').textContent = '';
                    }

                    // ── Image picker ──────────────────────────────────────────────

                    function psWzRefreshThumbs() {
                        var thumbs = document.querySelectorAll('.ps-media-thumb');
                        thumbs.forEach(function(el) {
                            var id    = el.getAttribute('data-id');
                            var pos   = _selected.indexOf(id);
                            var badge = el.querySelector('.ps-media-badge');
                            if (pos >= 0) {
                                el.style.borderColor = '#337ab7';
                                badge.style.display  = 'block';
                                badge.textContent    = pos + 1;
                            } else {
                                el.style.borderColor = 'transparent';
                                badge.style.display  = 'none';
                            }
                        });
                        var container = document.getElementById('ps-media-hidden-inputs');
                        container.innerHTML = '';
                        _selected.forEach(function(id) {
                            var inp = document.createElement('input');
                            inp.type  = 'hidden';
                            inp.name  = 'selected_media[]';
                            inp.value = id;
                            container.appendChild(inp);
                        });
                    }

                    document.querySelectorAll('.ps-media-thumb').forEach(function(el) {
                        el.addEventListener('click', function() {
                            var id  = this.getAttribute('data-id');
                            var pos = _selected.indexOf(id);
                            if (pos >= 0) { _selected.splice(pos, 1); } else { _selected.push(id); }
                            psWzRefreshThumbs();
                        });
                    });

                    // ── Upload sub-panel ──────────────────────────────────────────

                    window.psWzShowUpload = function() {
                        document.getElementById('ps-wz-main-panel').style.display    = 'none';
                        document.getElementById('ps-wz-upload-panel').style.display  = '';
                        document.getElementById('ps-wz-progress').style.display      = 'none';
                        document.getElementById('ps-wz-footer-nav').style.display    = 'none';
                        document.getElementById('ps-wz-footer-upload').style.display = 'flex';
                        document.getElementById('ps-upload-status').textContent      = '';
                    };

                    window.psWzHideUpload = function() {
                        document.getElementById('ps-wz-upload-panel').style.display  = 'none';
                        document.getElementById('ps-wz-main-panel').style.display    = '';
                        document.getElementById('ps-wz-progress').style.display      = '';
                        document.getElementById('ps-wz-footer-nav').style.display    = 'flex';
                        document.getElementById('ps-wz-footer-upload').style.display = 'none';
                        document.getElementById('ps-media-file-input').value         = '';
                        document.getElementById('ps-upload-alt').value               = '';
                        document.getElementById('ps-upload-category').value          = 'general';
                        document.getElementById('ps-upload-status').textContent      = '';
                        var btn = document.getElementById('ps-upload-submit-btn');
                        if (btn) { btn.disabled = false; }
                    };

                    window.psWzDoUpload = function() {
                        var fileInput = document.getElementById('ps-media-file-input');
                        var file      = fileInput.files && fileInput.files[0];
                        var status    = document.getElementById('ps-upload-status');
                        var btn       = document.getElementById('ps-upload-submit-btn');

                        if (!file) {
                            status.textContent = 'Please select a file.';
                            return;
                        }

                        var altText  = document.getElementById('ps-upload-alt').value.trim();
                        var category = document.getElementById('ps-upload-category').value;

                        status.textContent = 'Uploading…';
                        btn.disabled = true;

                        var fd = new FormData();
                        fd.append('media_file', file);
                        fd.append('alt_text',   altText);
                        fd.append('category',   category);
                        fd.append(_csrfName,    _csrfHash);

                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', _uploadUrl, true);
                        xhr.onload = function() {
                            btn.disabled = false;
                            try {
                                var res = JSON.parse(xhr.responseText);
                                if (res.csrf_hash) { _csrfHash = res.csrf_hash; }
                                if (res.success) {
                                    psWzAddUploadedThumb(res);
                                    psWzHideUpload();
                                } else {
                                    status.textContent = 'Error: ' + (res.error || 'Upload failed.');
                                }
                            } catch(e) {
                                status.textContent = 'Unexpected server response.';
                            }
                        };
                        xhr.onerror = function() {
                            btn.disabled = false;
                            status.textContent = 'Upload failed — network error.';
                        };
                        xhr.send(fd);
                    };

                    function psWzAddUploadedThumb(res) {
                        var empty = document.getElementById('ps-media-empty');
                        var grid  = document.getElementById('ps-media-grid');

                        if (empty) { empty.style.display = 'none'; }
                        grid.style.display = 'flex';

                        var div = document.createElement('div');
                        div.className = 'ps-media-thumb';
                        div.setAttribute('data-id',  String(res.media_id));
                        div.setAttribute('data-alt', res.alt_text || res.filename);
                        div.style.cssText = 'width:80px;height:80px;border-radius:4px;overflow:hidden;cursor:pointer;position:relative;border:2px solid transparent;flex-shrink:0;';

                        var img = document.createElement('img');
                        img.src = res.url;
                        img.alt = res.alt_text || '';
                        img.style.cssText = 'width:100%;height:100%;object-fit:cover;';

                        var badge = document.createElement('span');
                        badge.className = 'ps-media-badge';
                        badge.style.cssText = 'display:none;position:absolute;bottom:3px;right:3px;background:#337ab7;color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;font-weight:700;line-height:18px;text-align:center;';

                        div.appendChild(img);
                        div.appendChild(badge);
                        grid.appendChild(div);

                        div.addEventListener('click', function() {
                            var id  = this.getAttribute('data-id');
                            var pos = _selected.indexOf(id);
                            if (pos >= 0) { _selected.splice(pos, 1); } else { _selected.push(id); }
                            psWzRefreshThumbs();
                        });

                        _selected.push(String(res.media_id));
                        psWzRefreshThumbs();
                    }

                    psWzShowStep(1);
                })();
                </script>

                <?php } // end $is_published check ?>

            </div><!-- #tab-pages -->
