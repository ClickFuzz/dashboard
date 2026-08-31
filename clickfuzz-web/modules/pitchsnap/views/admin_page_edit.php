<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
if (!function_exists('clickfuzz_web_media_url')) {
    require_once FCPATH . 'modules/pitchsnap/helpers/pitchsnap_media_helper.php';
}
$_valid_types = [
    'homepage' => 'Homepage',
    'about' => 'About', 'service' => 'Service', 'service_area' => 'Service Area',
    'contact' => 'Contact', 'gallery' => 'Gallery', 'financing' => 'Financing',
    'faq' => 'FAQ', 'custom' => 'Custom',
];
$_media_cats = [
    'logo' => 'Logo', 'team' => 'Team', 'project' => 'Project',
    'equipment' => 'Equipment', 'award' => 'Award',
    'certification' => 'Certification', 'before_after' => 'Before/After',
    'general' => 'General',
];
$_csrf_name  = $this->security->get_csrf_token_name();
$_csrf_hash  = $this->security->get_csrf_hash();
$_is_trashed = $page->status === 'trash';
?>
<div id="wrapper">
    <div class="content">

        <!-- Back link -->
        <div style="margin-bottom:14px;">
            <a href="<?php echo e($detail_url); ?>" class="btn btn-default btn-sm">
                <i class="fa fa-arrow-left"></i> Back to Website Detail
            </a>
        </div>

        <!-- Page header -->
        <div class="row" style="margin-bottom:18px; border-bottom:1px solid #eee; padding-bottom:14px;">
            <div class="col-md-8">
                <h4 class="tw-font-semibold" style="margin:0 0 4px;">
                    <?php echo e($page->title); ?>
                </h4>
                <div style="font-size:13px;">
                    <?php echo ps_badge($page->status); ?>
                    &nbsp;<span class="label label-default"><?php echo e($_valid_types[$page->page_type] ?? ucfirst($page->page_type)); ?></span>
                    <?php if ($page->generation_status !== 'not_generated') { ?>
                    &nbsp;<span class="label <?php echo $page->generation_status === 'generated' ? 'label-success' : 'label-default'; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $page->generation_status)); ?>
                    </span>
                    <?php } ?>
                    &nbsp;<code style="font-size:11px;">/<?php echo e($page->slug); ?></code>
                </div>
            </div>
            <div class="col-md-4 text-right" style="padding-top:28px;">
                <?php if ($_is_trashed) { ?>
                <form method="POST" action="<?php echo admin_url('pitchsnap/page_restore/' . (int)$page->id); ?>" style="display:inline;">
                    <input type="hidden" name="<?php echo $_csrf_name; ?>" value="<?php echo $_csrf_hash; ?>">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fa fa-undo"></i> Restore Page
                    </button>
                </form>
                <?php } else { ?>
                <form method="POST" action="<?php echo admin_url('pitchsnap/page_trash/' . (int)$page->id); ?>" style="display:inline;">
                    <input type="hidden" name="<?php echo $_csrf_name; ?>" value="<?php echo $_csrf_hash; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Move this page to Trash?');">
                        <i class="fa fa-trash"></i> Trash Page
                    </button>
                </form>
                <?php } ?>
            </div>
        </div>

        <?php if ($_is_trashed) { ?>
        <div class="alert alert-warning">
            <i class="fa fa-trash"></i> This page is in Trash. Restore it to edit.
        </div>
        <?php } ?>

        <form method="POST" action="<?php echo admin_url('pitchsnap/page_save/' . (int)$page->id); ?>"
              id="ps-page-edit-form">
            <input type="hidden" name="<?php echo $_csrf_name; ?>" value="<?php echo $_csrf_hash; ?>">

            <div class="row">
                <!-- ── Main column ─────────────────────────────────────── -->
                <div class="col-md-8">

                    <!-- Panel: Page Config -->
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot15">Page</h5>

                            <div class="form-group">
                                <label>Page Name <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="ps-edit-title" class="form-control"
                                       value="<?php echo e($page->title); ?>"
                                       placeholder="e.g. AC Repair"
                                       <?php echo $_is_trashed ? 'disabled' : 'required'; ?>>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Page Type <span class="text-danger">*</span></label>
                                        <select name="page_type" class="form-control" <?php echo $_is_trashed ? 'disabled' : 'required'; ?>>
                                            <?php foreach ($_valid_types as $_tv => $_tl) { ?>
                                            <option value="<?php echo $_tv; ?>" <?php echo $page->page_type === $_tv ? 'selected' : ''; ?>>
                                                <?php echo $_tl; ?>
                                            </option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Parent Page</label>
                                        <select name="parent_page_id" class="form-control" <?php echo $_is_trashed ? 'disabled' : ''; ?>>
                                            <option value="">— None (top-level) —</option>
                                            <?php foreach ($parent_options as $_po) {
                                                if ((int)$_po->id === (int)$page->id) { continue; }
                                            ?>
                                            <option value="<?php echo (int)$_po->id; ?>"
                                                    <?php echo (int)$page->parent_page_id === (int)$_po->id ? 'selected' : ''; ?>>
                                                <?php echo e($_po->title); ?>
                                            </option>
                                            <?php } ?>
                                        </select>
                                        <small class="text-muted">Only active pages from this site are listed.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Slug <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-addon">/</span>
                                    <input type="text" name="slug" id="ps-edit-slug" class="form-control"
                                           value="<?php echo e($page->slug); ?>"
                                           pattern="[a-z0-9\-]+"
                                           title="Lowercase letters, numbers, and hyphens only"
                                           <?php echo $_is_trashed ? 'disabled' : 'required'; ?>>
                                </div>
                                <small class="text-muted">Lowercase, hyphens only. Changing the slug may break existing links.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Panel: SEO -->
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot15">SEO</h5>

                            <div class="form-group">
                                <label>Primary Keyword</label>
                                <input type="text" name="primary_keyword" class="form-control"
                                       value="<?php echo e($page->primary_keyword ?? ''); ?>"
                                       placeholder="e.g. AC repair Austin TX"
                                       <?php echo $_is_trashed ? 'disabled' : ''; ?>>
                                <small class="text-muted">Main keyword phrase this page should rank for.</small>
                            </div>

                            <div class="form-group">
                                <label>Supporting Keywords</label>
                                <textarea name="supporting_keywords" class="form-control" rows="3"
                                          placeholder="One keyword per line, or comma-separated"
                                          <?php echo $_is_trashed ? 'disabled' : ''; ?>><?php echo e($page->supporting_keywords ?? ''); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label>Meta Title</label>
                                <input type="text" name="meta_title" class="form-control"
                                       value="<?php echo e($page->meta_title ?? ''); ?>"
                                       placeholder="Optional — generator can create this"
                                       <?php echo $_is_trashed ? 'disabled' : ''; ?>>
                            </div>

                            <div class="form-group">
                                <label>Meta Description</label>
                                <textarea name="meta_description" class="form-control" rows="2"
                                          placeholder="Optional — generator can create this"
                                          <?php echo $_is_trashed ? 'disabled' : ''; ?>><?php echo e($page->meta_description ?? ''); ?></textarea>
                            </div>

                            <div class="form-group" style="margin-bottom:0;">
                                <div class="checkbox" style="margin:0;">
                                    <label>
                                        <input type="checkbox" name="noindex_page" value="1"
                                               <?php echo (int)($page->noindex_page ?? 0) ? 'checked' : ''; ?>
                                               <?php echo $_is_trashed ? 'disabled' : ''; ?>>
                                        Hide from search engines (noindex)
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Panel: Navigation -->
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot15">Navigation</h5>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="menu_primary" value="1"
                                                       <?php echo (int)$page->menu_primary ? 'checked' : ''; ?>
                                                       <?php echo $_is_trashed ? 'disabled' : ''; ?>>
                                                Include in primary menu
                                            </label>
                                        </div>
                                        <div class="checkbox">
                                            <label>
                                                <input type="checkbox" name="menu_footer" value="1"
                                                       <?php echo (int)$page->menu_footer ? 'checked' : ''; ?>
                                                       <?php echo $_is_trashed ? 'disabled' : ''; ?>>
                                                Include in footer menu
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Menu Label <small class="text-muted">(if different from page name)</small></label>
                                        <input type="text" name="menu_label" class="form-control"
                                               value="<?php echo e($page->menu_label ?? ''); ?>"
                                               placeholder="Defaults to page name"
                                               <?php echo $_is_trashed ? 'disabled' : ''; ?>>
                                    </div>
                                    <div class="form-group">
                                        <label>Menu Order</label>
                                        <input type="number" name="menu_order" class="form-control"
                                               value="<?php echo (int)$page->menu_order; ?>"
                                               min="0" step="1"
                                               <?php echo $_is_trashed ? 'disabled' : ''; ?>>
                                        <small class="text-muted">Lower numbers appear first.</small>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($page->parent_page_id)) { ?>
                            <div class="alert alert-info" style="font-size:12px; padding:8px 12px; margin-bottom:0;">
                                <i class="fa fa-info-circle"></i>
                                This page is a child page. When page publishing is implemented,
                                it will appear as a submenu item beneath its parent.
                            </div>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Panel: Generation Instructions -->
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot15">Generation Instructions</h5>
                            <div class="form-group" style="margin-bottom:0;">
                                <label>Instructions / Prompt Notes</label>
                                <textarea name="instructions" class="form-control" rows="5"
                                          placeholder="Describe what this page should contain, tone, key points, sections to include, etc."
                                          <?php echo $_is_trashed ? 'disabled' : ''; ?>><?php echo e($page->instructions ?? ''); ?></textarea>
                                <small class="text-muted">These notes guide AI page generation. Combined with Primary Keyword above.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Panel: Page Content (HTML editor) -->
                    <?php if ($current_gen && !empty($current_gen->html_content) && !$_is_trashed) { ?>
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot10">
                                Page Content
                                <a href="<?php echo admin_url('pitchsnap/page_preview/' . (int)$page->id); ?>"
                                   target="_blank" class="btn btn-info btn-xs" style="float:right; margin-top:-2px;">
                                    <i class="fa fa-eye"></i> Preview
                                </a>
                            </h5>
                            <p class="text-muted" style="font-size:12px; margin-bottom:10px;">
                                Edit the page HTML directly. Saving creates a new version and sets it as primary.
                            </p>
                            <textarea id="ps-page-html-editor" class="form-control" rows="22"
                                      style="font-family:'Courier New',Courier,monospace; font-size:11px; line-height:1.6; resize:vertical; tab-size:2;"
                                      ><?php echo htmlspecialchars($current_gen->html_content, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></textarea>
                            <div style="margin-top:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" onclick="psSavePageContent();">
                                    <i class="fa fa-save"></i> Save as New Version
                                </button>
                                <button type="button" class="btn btn-default btn-sm" onclick="psResetHtmlEditor();">
                                    Reset
                                </button>
                                <span id="ps-html-save-status" style="font-size:12px;"></span>
                            </div>
                        </div>
                    </div>
                    <?php } ?>

                    <!-- Panel: Page Media -->
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot10">Page Media</h5>
                            <p class="text-muted" style="font-size:12px; margin-bottom:12px;">
                                Select images from the site Media Library to associate with this page.
                                Media belongs to the site — selecting here creates a relationship only.
                            </p>

                            <!-- Attached media -->
                            <?php if (empty($page_media)) { ?>
                            <p class="text-muted" style="font-size:12px;">No media attached yet.</p>
                            <?php } else { ?>
                            <div class="row mbot15" id="ps-attached-media">
                                <?php foreach ($page_media as $_am) {
                                    $_aurl = clickfuzz_web_media_url($_am->site_id, $_am->filename);
                                ?>
                                <div class="col-md-3 col-sm-4" id="ps-pm-<?php echo (int)$_am->id; ?>" style="margin-bottom:10px;">
                                    <div style="border:2px solid #5cb85c; border-radius:4px; overflow:hidden; position:relative;">
                                        <img src="<?php echo e($_aurl); ?>" alt="<?php echo e($_am->alt_text ?? $_am->title); ?>"
                                             style="width:100%; height:80px; object-fit:cover; display:block;">
                                        <div style="padding:4px 6px; font-size:11px; background:#fff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            <?php echo e($_am->title ?: $_am->original_filename); ?>
                                        </div>
                                        <button type="button" class="btn btn-danger btn-xs"
                                                style="position:absolute; top:4px; right:4px;"
                                                onclick="ps_detach_media(<?php echo (int)$_am->id; ?>)"
                                                title="Detach">
                                            <i class="fa fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                            <?php } ?>

                            <!-- Site media library picker -->
                            <?php if (!empty($site_media)) { ?>
                            <div style="border-top:1px solid #eee; padding-top:12px; margin-top:4px;">
                                <p style="font-size:12px; font-weight:600; margin-bottom:8px;">Available from Site Library:</p>
                                <div class="row">
                                    <?php
                                    $attached_ids = array_map(fn($am) => (int)$am->id, $page_media);
                                    foreach ($site_media as $_sm) {
                                        $_surl = clickfuzz_web_media_url($_sm->site_id, $_sm->filename);
                                        $_is_attached = in_array((int)$_sm->id, $attached_ids, true);
                                    ?>
                                    <div class="col-md-3 col-sm-4" style="margin-bottom:10px;">
                                        <div style="border:2px solid <?php echo $_is_attached ? '#5cb85c' : '#ddd'; ?>; border-radius:4px; overflow:hidden; cursor:pointer;"
                                             id="ps-sml-<?php echo (int)$_sm->id; ?>"
                                             onclick="ps_toggle_media(<?php echo (int)$_sm->id; ?>, <?php echo $_is_attached ? 'true' : 'false'; ?>)">
                                            <img src="<?php echo e($_surl); ?>" alt="<?php echo e($_sm->alt_text ?? $_sm->title); ?>"
                                                 style="width:100%; height:70px; object-fit:cover; display:block;">
                                            <div style="padding:3px 5px; font-size:10px; background:#fff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                <?php echo e($_sm->title ?: $_sm->original_filename); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php } ?>
                                </div>
                            </div>
                            <?php } else { ?>
                            <p class="text-muted" style="font-size:12px; margin-bottom:0;">No site media available. Upload images in the Media Library on the Website tab.</p>
                            <?php } ?>
                        </div>
                    </div>

                </div><!-- /.col-md-8 -->

                <!-- ── Sidebar ──────────────────────────────────────────── -->
                <div class="col-md-4">

                    <!-- Page Generation + Publishing panel -->
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot10">Page Status</h5>

                            <?php
                            // Determine composite state for UI
                            $_is_published   = ($page->status === 'published');
                            $_is_generating  = ($page->generation_status === 'generating');
                            $_is_generated   = ($page->generation_status === 'generated' && $current_gen);
                            $_is_failed      = ($page->generation_status === 'failed');
                            // "has_newer_gen" = published but current gen was created after last publish
                            ?>

                            <?php if ($_is_generating) { ?>
                            <!-- ── Generating in progress ─────────────────── -->
                            <div class="alert alert-info" style="font-size:12px; padding:8px 12px;">
                                <i class="fa fa-spinner fa-spin"></i> Generation in progress.
                            </div>
                            <?php if ($_is_published && $live_url) { ?>
                            <a href="<?php echo e($live_url); ?>" target="_blank" class="btn btn-default btn-block mbot5">
                                <i class="fa fa-globe"></i> View Current Live
                            </a>
                            <?php } ?>
                            <button type="button" class="btn btn-default btn-block" disabled>
                                <i class="fa fa-spinner fa-spin"></i> Generating…
                            </button>

                            <?php } elseif ($_is_published && !$has_newer_gen) { ?>
                            <!-- ── Published, no newer draft ──────────────── -->
                            <p class="text-success" style="font-size:12px; margin-bottom:8px;">
                                <i class="fa fa-check-circle"></i> Page is live.
                            </p>
                            <?php if ($live_url) { ?>
                            <a href="<?php echo e($live_url); ?>" target="_blank" class="btn btn-success btn-block mbot5">
                                <i class="fa fa-globe"></i> View Live Page
                            </a>
                            <?php } ?>
                            <?php if (!$_is_trashed) { ?>
                            <button type="button" class="btn btn-primary btn-block mbot5"
                                    data-url="<?php echo e(admin_url('pitchsnap/page_publish/' . (int)$page->id)); ?>"
                                    data-confirm="Re-publish this page to WordPress?"
                                    onclick="psSubmitPublish(this);">
                                <i class="fa fa-upload"></i> Re-publish
                            </button>
                            <a href="<?php echo admin_url('pitchsnap/page_generate/' . (int)$page->id); ?>"
                               class="btn btn-warning btn-block"
                               onclick="return confirm('Regenerate this page? The live page stays up until you publish the new version.');">
                                <i class="fa fa-refresh"></i> Regenerate
                            </a>
                            <?php } ?>

                            <?php } elseif ($_is_published && $has_newer_gen && $_is_generated) { ?>
                            <!-- ── Published + newer generation ready ─────── -->
                            <p class="text-warning" style="font-size:12px; margin-bottom:8px;">
                                <i class="fa fa-upload"></i> New version ready to publish.
                            </p>
                            <?php if ($live_url) { ?>
                            <a href="<?php echo e($live_url); ?>" target="_blank" class="btn btn-default btn-block mbot5">
                                <i class="fa fa-globe"></i> View Current Live
                            </a>
                            <?php } ?>
                            <a href="<?php echo admin_url('pitchsnap/page_preview/' . (int)$page->id); ?>"
                               target="_blank" class="btn btn-info btn-block mbot5">
                                <i class="fa fa-eye"></i> Preview Draft
                            </a>
                            <?php if (!$_is_trashed) { ?>
                            <button type="button" class="btn btn-primary btn-block"
                                        data-url="<?php echo e(admin_url('pitchsnap/page_publish/' . (int)$page->id)); ?>"
                                        data-confirm="Publish this new version? The live page will be replaced."
                                        onclick="psSubmitPublish(this);">
                                    <i class="fa fa-upload"></i> Publish New Version
                                </button>
                            <a href="<?php echo admin_url('pitchsnap/page_generate/' . (int)$page->id); ?>"
                               class="btn btn-warning btn-block btn-xs" style="margin-top:4px;"
                               onclick="return confirm('Regenerate again? This will replace the current unpublished draft.');">
                                <i class="fa fa-refresh"></i> Regenerate Again
                            </a>
                            <?php } ?>

                            <?php } elseif ($_is_generated && !$_is_published) { ?>
                            <!-- ── Generated (draft) — show preview + publish ─ -->
                            <p class="text-success" style="font-size:12px; margin-bottom:8px;">
                                <i class="fa fa-check-circle"></i> Page generated — ready to publish.
                            </p>
                            <a href="<?php echo admin_url('pitchsnap/page_preview/' . (int)$page->id); ?>"
                               target="_blank" class="btn btn-info btn-block mbot5">
                                <i class="fa fa-eye"></i> Preview Page
                            </a>
                            <?php if (!$_is_trashed) { ?>
                            <button type="button" class="btn btn-primary btn-block"
                                        data-url="<?php echo e(admin_url('pitchsnap/page_publish/' . (int)$page->id)); ?>"
                                        onclick="psSubmitPublish(this);">
                                    <i class="fa fa-upload"></i> Publish Page
                                </button>
                            <a href="<?php echo admin_url('pitchsnap/page_generate/' . (int)$page->id); ?>"
                               class="btn btn-warning btn-block btn-xs" style="margin-top:4px;"
                               onclick="return confirm('Regenerate this page? The current draft will be replaced.');">
                                <i class="fa fa-refresh"></i> Regenerate
                            </a>
                            <?php } ?>

                            <?php } elseif ($_is_failed) { ?>
                            <!-- ── Failed generation ──────────────────────── -->
                            <div class="alert alert-danger" style="font-size:12px; padding:8px 12px; margin-bottom:8px;">
                                <i class="fa fa-exclamation-triangle"></i> Last generation failed.
                            </div>
                            <?php if ($_is_published && $live_url) { ?>
                            <a href="<?php echo e($live_url); ?>" target="_blank" class="btn btn-default btn-block mbot5">
                                <i class="fa fa-globe"></i> View Live Page
                            </a>
                            <?php } elseif ($_is_published && $current_gen) { ?>
                            <a href="<?php echo admin_url('pitchsnap/page_preview/' . (int)$page->id); ?>"
                               target="_blank" class="btn btn-default btn-block mbot5">
                                <i class="fa fa-eye"></i> Preview Previous Version
                            </a>
                            <?php } ?>
                            <?php if (!$_is_trashed && $generate_ready) { ?>
                            <a href="<?php echo admin_url('pitchsnap/page_generate/' . (int)$page->id); ?>"
                               class="btn btn-danger btn-block">
                                <i class="fa fa-bolt"></i> Retry Generation
                            </a>
                            <?php } ?>

                            <?php } else { ?>
                            <!-- ── Not yet generated ──────────────────────── -->
                            <?php if ($generate_ready) { ?>
                            <p class="text-success" style="font-size:12px; margin-bottom:10px;">
                                <i class="fa fa-check-circle"></i> Ready to generate.
                            </p>
                            <?php } else { ?>
                            <p class="text-muted" style="font-size:12px; margin-bottom:6px;">Missing before generation:</p>
                            <ul style="font-size:12px; padding-left:18px; margin-bottom:10px; color:#888;">
                                <?php foreach ($missing as $_miss) { ?>
                                <li><?php echo e($_miss); ?></li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                            <?php if (!$_is_trashed && $generate_ready) { ?>
                            <a href="<?php echo admin_url('pitchsnap/page_generate/' . (int)$page->id); ?>"
                               class="btn btn-primary btn-block">
                                <i class="fa fa-bolt"></i> Generate Page
                            </a>
                            <?php } else { ?>
                            <button type="button" class="btn btn-primary btn-block" disabled>
                                <i class="fa fa-bolt"></i> Generate Page
                            </button>
                            <?php } ?>
                            <?php } ?>

                        </div>
                    </div>

                    <?php if (!empty($generations)) { ?>
                    <!-- Version history panel -->
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot10">Version History</h5>
                            <?php $_pub_gen_id = (int)($page->published_generation_id ?? 0); ?>
                            <table class="table table-condensed" style="font-size:12px; margin-bottom:0;">
                                <thead><tr><th>#</th><th>Date</th><th>Source</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($generations as $_gi => $_gen) {
                                    $_ver_num = count($generations) - $_gi;
                                    $_is_pub  = ($_pub_gen_id > 0 && $_pub_gen_id === (int)$_gen->id);
                                    $_src = $_gen->source ?? 'ai_generated';
                                    $_src_label = $_src === 'manual_edit' ? 'Edited' : ($_src === 'homepage_seed' ? 'Seeded' : 'AI');
                                ?>
                                <tr class="<?php echo $_gen->is_current ? 'success' : ''; ?>">
                                    <td>
                                        v<?php echo $_ver_num; ?>
                                        <?php if ($_gen->is_current) { ?>&nbsp;<span class="label label-success" style="font-size:9px;">primary</span><?php } ?>
                                        <?php if ($_is_pub) { ?>&nbsp;<span class="label label-primary" style="font-size:9px;">published</span><?php } ?>
                                    </td>
                                    <td style="white-space:nowrap;"><?php echo _dt($_gen->dateadded); ?></td>
                                    <td><?php echo $_src_label; ?></td>
                                    <td style="white-space:nowrap;">
                                        <a href="<?php echo admin_url('pitchsnap/page_preview/' . (int)$page->id . '?gen=' . (int)$_gen->id); ?>"
                                           target="_blank" title="Preview" style="margin-right:4px;">
                                            <i class="fa fa-eye"></i>
                                        </a>
                                        <?php if (!$_gen->is_current) { ?>
                                        <button type="button" class="btn btn-xs btn-default" title="Set as primary"
                                                data-url="<?php echo e(admin_url('pitchsnap/page_generation_set_current/' . (int)$_gen->id)); ?>"
                                                onclick="psSubmitPublish(this);">Use</button>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php } ?>

                    <!-- Page info -->
                    <div class="panel_s">
                        <div class="panel-body">
                            <h5 class="tw-font-semibold mbot10">Page Info</h5>
                            <table class="table table-condensed" style="font-size:12px; margin-bottom:0;">
                                <tr><th width="45%">ID</th><td>#<?php echo (int)$page->id; ?></td></tr>
                                <tr><th>Status</th><td><?php echo ucfirst($page->status); ?></td></tr>
                                <tr><th>Created</th><td><?php echo _dt($page->dateadded); ?></td></tr>
                                <tr><th>Updated</th><td><?php echo _dt($page->dateupdated); ?></td></tr>
                                <?php if ($page->status === 'published' && !empty($page->published_at)) { ?>
                                <tr><th>Published</th><td><?php echo _dt($page->published_at); ?></td></tr>
                                <?php } ?>
                                <?php if ($live_url) { ?>
                                <tr><th>Live URL</th><td><a href="<?php echo e($live_url); ?>" target="_blank" style="word-break:break-all;"><?php echo e($live_url); ?></a></td></tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>

                </div><!-- /.col-md-4 -->
            </div><!-- /.row -->

            <!-- Save button -->
            <?php if (!$_is_trashed) { ?>
            <div class="mtop5 mbot20">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i> Save Changes
                </button>
                <a href="<?php echo e($detail_url); ?>" class="btn btn-default mright5" style="margin-left:8px;">
                    Cancel
                </a>
            </div>
            <?php } ?>

        </form>

    </div><!-- /.content -->
</div><!-- #wrapper -->

<?php init_tail(); ?>
<script>
var PS_PAGE_ID          = <?php echo (int)$page->id; ?>;
var PS_CSRF_NAME        = <?php echo json_encode($this->security->get_csrf_token_name()); ?>;
var PS_CSRF_HASH        = <?php echo json_encode($this->security->get_csrf_hash()); ?>;
var PS_ATTACH_URL       = '<?php echo admin_url("pitchsnap/page_media_attach/" . (int)$page->id); ?>';
var PS_DETACH_URL       = '<?php echo admin_url("pitchsnap/page_media_detach/" . (int)$page->id); ?>';
var PS_CONTENT_SAVE_URL = '<?php echo admin_url("pitchsnap/page_content_save/" . (int)$page->id); ?>';
var psOriginalHtml      = <?php echo json_encode($current_gen ? ($current_gen->html_content ?? '') : ''); ?>;

function psSavePageContent() {
    var editor = document.getElementById('ps-page-html-editor');
    var status = document.getElementById('ps-html-save-status');
    if (!editor) { return; }
    status.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving&hellip;';
    var body = PS_CSRF_NAME + '=' + encodeURIComponent(PS_CSRF_HASH)
             + '&html=' + encodeURIComponent(editor.value);
    fetch(PS_CONTENT_SAVE_URL, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(function(r) { return r.json(); })
    .then(function(j) {
        if (j.success) {
            psOriginalHtml = editor.value;
            status.innerHTML = '<span class="text-success"><i class="fa fa-check"></i> ' + j.message + ' Reloading&hellip;</span>';
            setTimeout(function() { window.location.reload(); }, 1200);
        } else {
            status.innerHTML = '<span class="text-danger"><i class="fa fa-times"></i> ' + j.error + '</span>';
        }
    })
    .catch(function() {
        status.innerHTML = '<span class="text-danger"><i class="fa fa-times"></i> Request failed.</span>';
    });
}

function psResetHtmlEditor() {
    var editor = document.getElementById('ps-page-html-editor');
    if (editor) { editor.value = psOriginalHtml; }
    document.getElementById('ps-html-save-status').innerHTML = '';
}

function psSubmitPublish(btn) {
    var url = btn.getAttribute('data-url');
    var msg = btn.getAttribute('data-confirm');
    if (msg && !confirm(msg)) { return; }
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = url;
    var t = document.createElement('input');
    t.type = 'hidden'; t.name = PS_CSRF_NAME; t.value = PS_CSRF_HASH;
    f.appendChild(t);
    document.body.appendChild(f);
    f.submit();
}

// Slug auto-suggest from title
document.getElementById('ps-edit-title').addEventListener('input', function() {
    var current = document.getElementById('ps-edit-slug').value;
    if (current !== '' && current !== '<?php echo e($page->slug); ?>') { return; }
    var slug = this.value.toLowerCase()
        .replace(/[^a-z0-9\s\-]/g, '')
        .trim().replace(/\s+/g, '-')
        .replace(/-+/g, '-');
    document.getElementById('ps-edit-slug').value = slug;
});

function ps_toggle_media(media_id, is_attached) {
    var url = is_attached ? PS_DETACH_URL : PS_ATTACH_URL;
    var data = PS_CSRF_NAME + '=' + encodeURIComponent(PS_CSRF_HASH) + '&media_id=' + media_id;
    var el = document.getElementById('ps-sml-' + media_id);
    fetch(url, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data})
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.success) {
                if (is_attached) {
                    el.style.borderColor = '#ddd';
                    el.setAttribute('onclick', 'ps_toggle_media(' + media_id + ', false)');
                    var attached = document.getElementById('ps-pm-' + media_id);
                    if (attached) { attached.parentNode.removeChild(attached); }
                } else {
                    el.style.borderColor = '#5cb85c';
                    el.setAttribute('onclick', 'ps_toggle_media(' + media_id + ', true)');
                }
            }
        });
}

function ps_detach_media(media_id) {
    var data = PS_CSRF_NAME + '=' + encodeURIComponent(PS_CSRF_HASH) + '&media_id=' + media_id;
    fetch(PS_DETACH_URL, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data})
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.success) {
                var el = document.getElementById('ps-pm-' + media_id);
                if (el) { el.parentNode.removeChild(el); }
                var sml = document.getElementById('ps-sml-' + media_id);
                if (sml) {
                    sml.style.borderColor = '#ddd';
                    sml.setAttribute('onclick', 'ps_toggle_media(' + media_id + ', false)');
                }
            }
        });
}
</script>
