<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">

        <div class="row">
            <div class="col-md-12">
                <a href="<?php echo admin_url('pitchsnap/detail/' . (int) $website->id); ?>"
                   class="btn btn-default btn-sm mbot10">
                    <i class="fa fa-arrow-left"></i> Back to Website #<?php echo (int) $website->id; ?>
                </a>
                <h4 class="tw-font-semibold" style="display:inline-block; margin-left:10px;">
                    Edit HTML — Website #<?php echo (int) $website->id; ?>
                </h4>
            </div>
        </div>

        <?php if (!empty($flash_error)) { ?>
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-danger"><?php echo e($flash_error); ?></div>
            </div>
        </div>
        <?php } ?>

        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <p class="text-muted" style="font-size:12px; margin-bottom:12px;">
                            Edit the stored HTML below. Saving will update the stored HTML and immediately redeploy the preview.
                            The runtime widget script tag and noindex protection are re-injected automatically.
                            No AI call is made.
                        </p>
                        <form method="post"
                              action="<?php echo admin_url('pitchsnap/edit_html/' . (int) $website->id); ?>">
                            <input type="hidden"
                                   name="<?php echo $this->security->get_csrf_token_name(); ?>"
                                   value="<?php echo $this->security->get_csrf_hash(); ?>">
                            <div class="form-group">
                                <textarea name="html"
                                          id="ps_html_editor"
                                          class="form-control"
                                          rows="36"
                                          style="font-family:monospace; font-size:12px; resize:vertical; white-space:pre;"
                                          spellcheck="false"
                                ><?php echo htmlspecialchars($website->generation_result, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa fa-save"></i> Save &amp; Redeploy
                                </button>
                                <a href="<?php echo admin_url('pitchsnap/detail/' . (int) $website->id); ?>"
                                   class="btn btn-default">
                                    Cancel
                                </a>
                                <?php if (!empty($website->preview_url)) { ?>
                                <a href="<?php echo e($website->preview_url); ?>"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="btn btn-success btn-sm"
                                   style="margin-left:auto;">
                                    <i class="fa fa-external-link"></i> View Current Preview
                                </a>
                                <?php } ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
<?php init_tail(); ?>
<script>
// Prevent accidental navigation away from unsaved edits
(function () {
    var original = document.getElementById('ps_html_editor').value;
    window.addEventListener('beforeunload', function (e) {
        if (document.getElementById('ps_html_editor').value !== original) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    document.querySelector('form').addEventListener('submit', function () {
        window.removeEventListener('beforeunload', arguments.callee);
    });
}());
</script>
