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
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                            <h4 class="tw-font-semibold tw-mb-0">Onboarding Flows</h4>
                            <button type="button" class="btn btn-primary btn-sm"
                                data-toggle="modal" data-target="#cfFlowModal"
                                onclick="cfOpenNew()">
                                <i class="fa fa-plus"></i> New Flow
                            </button>
                        </div>

                        <?php if (empty($flows)) { ?>
                        <p class="text-muted">No onboarding flows yet. Create one to get started.</p>
                        <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover dt-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Last Updated</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($flows as $f) { ?>
                                    <tr>
                                        <td><?php echo e($f['name']); ?></td>
                                        <td><?php echo e($f['description'] ?: '—'); ?></td>
                                        <td>
                                            <?php if ($f['status'] === 'active') { ?>
                                            <span class="label label-success">Active</span>
                                            <?php } else { ?>
                                            <span class="label label-default">Inactive</span>
                                            <?php } ?>
                                        </td>
                                        <td><?php echo _dt($f['updated_at'] ?: $f['created_at']); ?></td>
                                        <td class="text-right" style="white-space:nowrap;">
                                            <a href="<?php echo admin_url('pitchsnap/flow_sections/' . (int) $f['id']); ?>" class="btn btn-info btn-xs">
                                                <i class="fa fa-list"></i> Sections
                                            </a>
                                            <button type="button" class="btn btn-default btn-xs"
                                                onclick="cfOpenEdit(<?php echo (int) $f['id']; ?>, <?php echo htmlspecialchars(json_encode($f['name']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string) $f['description']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($f['status']), ENT_QUOTES); ?>)"
                                                data-toggle="modal" data-target="#cfFlowModal">
                                                <i class="fa fa-pencil"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-<?php echo $f['status'] === 'active' ? 'warning' : 'success'; ?> btn-xs"
                                                onclick="cfToggle(<?php echo (int) $f['id']; ?>, this)">
                                                <i class="fa fa-<?php echo $f['status'] === 'active' ? 'pause' : 'play'; ?>"></i>
                                                <?php echo $f['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                            <button type="button" class="btn btn-default btn-xs"
                                                onclick="cfDuplicate(<?php echo (int) $f['id']; ?>)">
                                                <i class="fa fa-copy"></i> Duplicate
                                            </button>
                                            <button type="button" class="btn btn-danger btn-xs"
                                                onclick="cfDelete(<?php echo (int) $f['id']; ?>, <?php echo htmlspecialchars(json_encode($f['name']), ENT_QUOTES); ?>)">
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
<div class="modal fade" id="cfFlowModal" tabindex="-1" role="dialog" aria-labelledby="cfFlowModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="cfFlowModalLabel">New Onboarding Flow</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cf_flow_id" value="">
                <div class="form-group">
                    <label>Name <span class="text-danger">*</span></label>
                    <input type="text" id="cf_flow_name" class="form-control" placeholder="e.g. Standard Onboarding">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="cf_flow_desc" class="form-control" rows="3" placeholder="Internal notes about this flow"></textarea>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="cf_flow_status" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="cfSaveBtn" onclick="cfSave()">Save</button>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
<script>
var _cf_csrf = {n: '<?php echo $csrf_n; ?>', h: '<?php echo $csrf_h; ?>'};

function _cfPost(url, extra) {
    var params = Object.assign({[_cf_csrf.n]: _cf_csrf.h}, extra || {});
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams(params)
    }).then(function(r) { return r.json(); });
}

function cfOpenNew() {
    document.getElementById('cfFlowModalLabel').textContent = 'New Onboarding Flow';
    document.getElementById('cf_flow_id').value = '';
    document.getElementById('cf_flow_name').value = '';
    document.getElementById('cf_flow_desc').value = '';
    document.getElementById('cf_flow_status').value = 'active';
}

function cfOpenEdit(id, name, desc, status) {
    document.getElementById('cfFlowModalLabel').textContent = 'Edit Onboarding Flow';
    document.getElementById('cf_flow_id').value = id;
    document.getElementById('cf_flow_name').value = name || '';
    document.getElementById('cf_flow_desc').value = desc || '';
    document.getElementById('cf_flow_status').value = status || 'active';
}

function cfSave() {
    var name = document.getElementById('cf_flow_name').value.trim();
    if (!name) { alert('Name is required.'); return; }
    var btn = document.getElementById('cfSaveBtn');
    btn.disabled = true;
    _cfPost('<?php echo admin_url('pitchsnap/flow_save'); ?>', {
        id:          document.getElementById('cf_flow_id').value,
        name:        name,
        description: document.getElementById('cf_flow_desc').value,
        status:      document.getElementById('cf_flow_status').value
    }).then(function(d) {
        if (d.success) { location.reload(); }
        else { alert(d.message || 'Error saving flow.'); btn.disabled = false; }
    }).catch(function() { alert('Request failed.'); btn.disabled = false; });
}

function cfToggle(id, btn) {
    btn.disabled = true;
    _cfPost('<?php echo admin_url('pitchsnap/flow_toggle/'); ?>' + id)
        .then(function(d) { if (d.success) { location.reload(); } else { btn.disabled = false; } })
        .catch(function() { btn.disabled = false; });
}

function cfDuplicate(id) {
    if (!confirm('Duplicate this flow?')) { return; }
    _cfPost('<?php echo admin_url('pitchsnap/flow_duplicate/'); ?>' + id)
        .then(function(d) { if (d.success) { location.reload(); } })
        .catch(function() {});
}

function cfDelete(id, name) {
    if (!confirm('Delete flow "' + name + '" and all its sections and questions? This cannot be undone.')) { return; }
    _cfPost('<?php echo admin_url('pitchsnap/flow_delete/'); ?>' + id)
        .then(function(d) {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Could not delete flow.'); }
        }).catch(function() {});
}
</script>
