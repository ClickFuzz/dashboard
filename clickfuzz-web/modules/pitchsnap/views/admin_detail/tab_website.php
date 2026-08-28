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


                <!-- ── Generate Pages (only when site is published) ──────── -->
                <?php if (!empty($site) && $site->status === 'published') { ?>

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

                <!-- ── Media Library ─────────────────────────────────────── -->
                <?php
                $_media_cats = [
                    'logo' => 'Logo', 'team' => 'Team', 'project' => 'Project',
                    'equipment' => 'Equipment', 'award' => 'Award',
                    'certification' => 'Certification', 'before_after' => 'Before/After',
                    'general' => 'General',
                ];
                if (!function_exists('clickfuzz_web_media_url')) {
                    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_media_helper.php';
                }
                ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="tw-font-semibold mbot15">Media Library</h5>

                        <!-- Upload form -->
                        <form method="POST" action="<?php echo admin_url('pitchsnap/media_upload/' . (int) $site->id); ?>"
                              enctype="multipart/form-data" style="background:#f9f9f9; padding:14px; border:1px solid #e5e5e5; border-radius:4px; margin-bottom:18px;">
                            <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group" style="margin-bottom:8px;">
                                        <label style="font-size:12px; font-weight:600;">File <span class="text-danger">*</span></label>
                                        <input type="file" name="media_file" accept="image/*" required class="form-control input-sm">
                                        <small class="text-muted">JPEG, PNG, GIF, WebP, SVG — max 10 MB</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group" style="margin-bottom:8px;">
                                        <label style="font-size:12px; font-weight:600;">Title</label>
                                        <input type="text" name="title" class="form-control input-sm" placeholder="Optional title">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group" style="margin-bottom:8px;">
                                        <label style="font-size:12px; font-weight:600;">Category</label>
                                        <select name="category" class="form-control input-sm">
                                            <?php foreach ($_media_cats as $_k => $_v) { ?>
                                            <option value="<?php echo $_k; ?>"><?php echo $_v; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group" style="margin-bottom:8px;">
                                        <label style="font-size:12px; font-weight:600;">Alt Text</label>
                                        <input type="text" name="alt_text" class="form-control input-sm" placeholder="Describe the image">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fa fa-upload"></i> Upload
                            </button>
                        </form>

                        <!-- Media grid -->
                        <?php if (empty($site_media)) { ?>
                        <p class="text-muted" style="margin-bottom:0;">No media uploaded yet.</p>
                        <?php } else { ?>
                        <div class="row">
                            <?php foreach ($site_media as $_m) {
                                $_murl = clickfuzz_web_media_url($_m->site_id, $_m->filename);
                            ?>
                            <div class="col-md-3 col-sm-4" style="margin-bottom:16px;">
                                <div style="border:1px solid #ddd; border-radius:4px; overflow:hidden; background:#fff;">
                                    <div style="height:110px; background:#f5f5f5; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                        <?php if (in_array($_m->mime_type ?? '', ['image/svg+xml'])) { ?>
                                        <img src="<?php echo e($_murl); ?>" alt="<?php echo e($_m->alt_text ?? $_m->title); ?>"
                                             style="max-height:100px; max-width:100%; object-fit:contain;">
                                        <?php } else { ?>
                                        <img src="<?php echo e($_murl); ?>" alt="<?php echo e($_m->alt_text ?? $_m->title); ?>"
                                             style="max-height:110px; max-width:100%; object-fit:cover; width:100%;">
                                        <?php } ?>
                                    </div>
                                    <div style="padding:8px;">
                                        <div style="font-size:12px; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo e($_m->title); ?>">
                                            <?php echo e($_m->title ?: $_m->original_filename); ?>
                                        </div>
                                        <div style="font-size:11px; color:#888;">
                                            <?php echo e($_media_cats[$_m->category] ?? ucfirst($_m->category)); ?>
                                        </div>
                                        <div class="mtop8 tw-flex tw-gap-1">
                                            <button class="btn btn-default btn-xs"
                                                    onclick="ps_edit_media(<?php echo (int)$_m->id; ?>, <?php echo json_encode($_m->title); ?>, <?php echo json_encode($_m->description ?? ''); ?>, <?php echo json_encode($_m->alt_text ?? ''); ?>, <?php echo json_encode($_m->category); ?>)">
                                                <i class="fa fa-pencil"></i>
                                            </button>
                                            <form method="POST" action="<?php echo admin_url('pitchsnap/media_delete/' . (int)$_m->id); ?>" style="display:inline;">
                                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                                <button type="submit" class="btn btn-danger btn-xs"
                                                        onclick="return confirm('Remove this media? It will fail if currently attached to pages.');">
                                                    <i class="fa fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php } ?>
                        </div>
                        <?php } ?>
                    </div>
                </div>

                <!-- Add Page modal -->
                <div class="modal fade" id="ps-add-page-modal" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <form method="POST" action="<?php echo admin_url('pitchsnap/page_add/' . (int) $site->id); ?>">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    <h4 class="modal-title">Add Page</h4>
                                </div>
                                <div class="modal-body">
                                    <div class="form-group">
                                        <label>Page Name <span class="text-danger">*</span></label>
                                        <input type="text" name="title" id="ps-new-page-title" class="form-control"
                                               placeholder="e.g. AC Repair" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Page Type <span class="text-danger">*</span></label>
                                        <select name="page_type" class="form-control" required>
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
                                               placeholder="e.g. ac-repair" required pattern="[a-z0-9\-]+"
                                               title="Lowercase letters, numbers, and hyphens only">
                                        <small class="text-muted">Lowercase, hyphens only. Auto-suggested from page name.</small>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Create Page</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Media modal -->
                <div class="modal fade" id="ps-edit-media-modal" tabindex="-1" role="dialog">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <form method="POST" id="ps-edit-media-form" action="">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    <h4 class="modal-title">Edit Media</h4>
                                </div>
                                <div class="modal-body">
                                    <div class="form-group">
                                        <label>Title</label>
                                        <input type="text" name="title" id="ps-media-edit-title" class="form-control">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt Text</label>
                                        <input type="text" name="alt_text" id="ps-media-edit-alt" class="form-control">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="description" id="ps-media-edit-desc" class="form-control" rows="2"></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label>Category</label>
                                        <select name="category" id="ps-media-edit-cat" class="form-control">
                                            <?php foreach ($_media_cats as $_k => $_v) { ?>
                                            <option value="<?php echo $_k; ?>"><?php echo $_v; ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <script>
                // Auto-suggest slug from page name
                document.getElementById('ps-new-page-title').addEventListener('input', function() {
                    var slug = this.value.toLowerCase()
                        .replace(/[^a-z0-9\s\-]/g, '')
                        .trim().replace(/\s+/g, '-')
                        .replace(/-+/g, '-');
                    document.getElementById('ps-new-page-slug').value = slug;
                });

                function ps_edit_media(id, title, desc, alt, cat) {
                    document.getElementById('ps-edit-media-form').action =
                        '<?php echo admin_url("pitchsnap/media_save/"); ?>' + id;
                    document.getElementById('ps-media-edit-title').value = title || '';
                    document.getElementById('ps-media-edit-desc').value  = desc  || '';
                    document.getElementById('ps-media-edit-alt').value   = alt   || '';
                    document.getElementById('ps-media-edit-cat').value   = cat   || 'general';
                    $('#ps-edit-media-modal').modal('show');
                }
                </script>

                <?php } // end site published check ?>

            </div><!-- #tab-website -->
