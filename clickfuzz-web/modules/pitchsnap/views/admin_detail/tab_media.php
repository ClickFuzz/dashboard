<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB — MEDIA
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-media">

                <?php if (!$is_published) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <p class="text-muted" style="margin-bottom:0;">Media is available after the site is published.</p>
                    </div>
                </div>
                <?php } else { ?>

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

                <?php } // end $is_published check ?>

            </div><!-- #tab-media -->
