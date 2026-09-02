<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$CI     =& get_instance();
$csrf_n = $CI->security->get_csrf_token_name();
$csrf_h = $CI->security->get_csrf_hash();
?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <ol class="breadcrumb">
                    <li><a href="<?php echo admin_url('pitchsnap/flows'); ?>">Onboarding Flows</a></li>
                    <li class="active">Usage Tags</li>
                </ol>

                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                            <h4 class="tw-font-semibold tw-mb-0">Usage Tags</h4>
                            <button type="button" class="btn btn-primary btn-sm"
                                onclick="cfTagOpenNew()" data-toggle="modal" data-target="#cfTagModal">
                                <i class="fa fa-plus"></i> New Tag
                            </button>
                        </div>
                        <p class="text-muted" style="margin-bottom:16px;">
                            Tags describe which downstream systems may consume a question's answer. They are labels only — they do not execute actions.
                        </p>

                        <?php if (empty($usage_tags)) { ?>
                        <p class="text-muted">No tags yet.</p>
                        <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Slug</th>
                                        <th>Description</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usage_tags as $tag) { ?>
                                    <tr>
                                        <td><?php echo e($tag['name']); ?></td>
                                        <td><code><?php echo e($tag['slug']); ?></code></td>
                                        <td><?php echo $tag['description'] ? e($tag['description']) : '<span class="text-muted">—</span>'; ?></td>
                                        <td class="text-right" style="white-space:nowrap;">
                                            <button type="button" class="btn btn-default btn-xs"
                                                onclick="cfTagOpenEdit(<?php echo (int) $tag['id']; ?>, <?php echo json_encode($tag['name']); ?>, <?php echo json_encode($tag['slug']); ?>, <?php echo json_encode((string) $tag['description']); ?>)"
                                                data-toggle="modal" data-target="#cfTagModal">
                                                <i class="fa fa-pencil"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-danger btn-xs"
                                                onclick="cfTagDelete(<?php echo (int) $tag['id']; ?>, <?php echo json_encode($tag['name']); ?>)">
                                                <i class="fa fa-trash"></i> Delete
                                            </button>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create / Edit Modal -->
<div class="modal fade" id="cfTagModal" tabindex="-1" role="dialog" aria-labelledby="cfTagModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="cfTagModalLabel">New Tag</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cf_tag_id" value="">
                <div class="form-group">
                    <label>Name <span class="text-danger">*</span></label>
                    <input type="text" id="cf_tag_name" class="form-control" placeholder="e.g. Customer Profile">
                </div>
                <div class="form-group">
                    <label>Slug <span class="text-danger">*</span></label>
                    <input type="text" id="cf_tag_slug" class="form-control" placeholder="e.g. customer_profile" style="font-family:monospace;">
                    <p class="help-block" style="margin-top:4px;font-size:11px;">Lowercase letters, numbers, underscores. Stable identifier — treat as permanent after creation.</p>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" id="cf_tag_desc" class="form-control" placeholder="Optional description">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="cfTagSaveBtn" onclick="cfTagSave()">Save</button>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
<script>
var _cf_csrf = {n: '<?php echo $csrf_n; ?>', h: '<?php echo $csrf_h; ?>'};

function _cfPost(url, params) {
    return fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: params
    }).then(function(r) { return r.json(); });
}

function _cfParams(extra) {
    var p = new URLSearchParams();
    p.append(_cf_csrf.n, _cf_csrf.h);
    if (extra) { Object.keys(extra).forEach(function(k) { p.append(k, extra[k]); }); }
    return p;
}

function cfTagOpenNew() {
    document.getElementById('cfTagModalLabel').textContent = 'New Tag';
    document.getElementById('cf_tag_id').value  = '';
    document.getElementById('cf_tag_name').value = '';
    document.getElementById('cf_tag_slug').value = '';
    document.getElementById('cf_tag_desc').value = '';
}

function cfTagOpenEdit(id, name, slug, desc) {
    document.getElementById('cfTagModalLabel').textContent = 'Edit Tag';
    document.getElementById('cf_tag_id').value  = id;
    document.getElementById('cf_tag_name').value = name || '';
    document.getElementById('cf_tag_slug').value = slug || '';
    document.getElementById('cf_tag_desc').value = desc || '';
}

document.getElementById('cf_tag_name').addEventListener('blur', function() {
    var slugField = document.getElementById('cf_tag_slug');
    if (slugField.value !== '') { return; }
    var slug = this.value.trim().toLowerCase().replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, '_');
    if (slug) { slugField.value = slug; }
});

function cfTagSave() {
    var name = document.getElementById('cf_tag_name').value.trim();
    var slug = document.getElementById('cf_tag_slug').value.trim().toLowerCase();
    if (!name) { alert('Name is required.'); return; }
    if (!slug)  { alert('Slug is required.'); return; }
    var btn = document.getElementById('cfTagSaveBtn');
    btn.disabled = true;
    _cfPost('<?php echo admin_url('pitchsnap/usage_tag_save'); ?>', _cfParams({
        id:          document.getElementById('cf_tag_id').value,
        name:        name,
        slug:        slug,
        description: document.getElementById('cf_tag_desc').value.trim()
    })).then(function(d) {
        if (d.success) { location.reload(); }
        else { alert(d.message || 'Error saving tag.'); btn.disabled = false; }
    }).catch(function() { alert('Request failed.'); btn.disabled = false; });
}

function cfTagDelete(id, name) {
    if (!confirm('Delete tag "' + name + '"?\n\nThis will fail if the tag is currently assigned to any questions.')) { return; }
    _cfPost('<?php echo admin_url('pitchsnap/usage_tag_delete/'); ?>' + id, _cfParams())
        .then(function(d) {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Could not delete tag.'); }
        }).catch(function() {});
}
</script>
