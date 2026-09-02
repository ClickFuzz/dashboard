<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$_csrf_n  = $this->security->get_csrf_token_name();
$_csrf_h  = $this->security->get_csrf_hash();
$_site_id = !empty($site) ? (int) $site->id : 0;
?>
<!-- ══════════════════════════════════════════════════════════
     TAB — DATA
     ══════════════════════════════════════════════════════════ -->
<div role="tabpanel" class="tab-pane" id="tab-data">
<script>
var _cfSD_csrf   = {n: '<?php echo $_csrf_n; ?>', h: '<?php echo $_csrf_h; ?>'};
var _cfSD_siteId = <?php echo $_site_id; ?>;
</script>

<?php if (!$_site_id) { ?>
    <p class="text-muted">No site record exists for this website yet.</p>
<?php } else { ?>
    <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
        <h4 class="tw-font-semibold tw-mb-0">Site Data</h4>
        <button type="button" class="btn btn-primary btn-sm"
            onclick="cfSDOpenNew()" data-toggle="modal" data-target="#cfSDModal">
            <i class="fa fa-plus"></i> Add Entry
        </button>
    </div>

    <?php if (empty($site_data)) { ?>
    <p class="text-muted">No data stored yet.</p>
    <?php } else { ?>
    <div class="table-responsive">
        <table class="table table-bordered table-hover table-condensed">
            <thead>
                <tr>
                    <th style="width:220px;">Data Key</th>
                    <th>Value</th>
                    <th style="width:150px;">Last Updated</th>
                    <th style="width:80px;" class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($site_data as $_d) {
                    $_decoded = @json_decode($_d['value'], true);
                    $_is_json = (json_last_error() === JSON_ERROR_NONE && (is_array($_decoded) || is_object($_decoded)));
                ?>
                <tr>
                    <td><code><?php echo e($_d['data_key']); ?></code></td>
                    <td>
                        <?php if ($_is_json) { ?>
                        <pre style="margin:0;font-size:11px;background:none;border:none;padding:0;white-space:pre-wrap;word-break:break-word;"><?php echo e(json_encode($_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                        <?php } else { ?>
                        <?php echo nl2br(e($_d['value'])); ?>
                        <?php } ?>
                    </td>
                    <td><small class="text-muted"><?php echo e($_d['updated_at'] ?: $_d['created_at']); ?></small></td>
                    <td class="text-right" style="white-space:nowrap;">
                        <button type="button" class="btn btn-default btn-xs"
                            onclick="cfSDOpenEdit(<?php echo (int) $_d['id']; ?>, <?php echo htmlspecialchars(json_encode($_d['data_key']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string) $_d['value']), ENT_QUOTES); ?>)"
                            data-toggle="modal" data-target="#cfSDModal">
                            <i class="fa fa-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-danger btn-xs"
                            onclick="cfSDDelete(<?php echo (int) $_d['id']; ?>, <?php echo htmlspecialchars(json_encode($_d['data_key']), ENT_QUOTES); ?>)">
                            <i class="fa fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>
<?php } ?>
</div><!-- #tab-data -->

<!-- Site Data Modal -->
<div class="modal fade" id="cfSDModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title" id="cfSDModalLabel">Add Data Entry</h4>
            </div>
            <div class="modal-body">
                <div class="form-group" id="cfSDKeyGroup">
                    <label>Data Key <span class="text-danger">*</span></label>
                    <input type="text" id="cfSD_key" class="form-control" style="font-family:monospace;" placeholder="e.g. business.name">
                    <p class="help-block" style="font-size:11px;margin-top:4px;">Lowercase, dot-separated segments (<code>business.name</code>). Stable — cannot be renamed after creation.</p>
                </div>
                <div class="form-group">
                    <label>Value <span class="text-danger">*</span></label>
                    <textarea id="cfSD_value" class="form-control" rows="4" placeholder="Enter value…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="cfSDSaveBtn" onclick="cfSDSave()">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
function _cfSDPost(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: body
    }).then(function(r) { return r.json(); });
}
function _cfSDParams(extra) {
    var p = new URLSearchParams();
    p.append(_cfSD_csrf.n, _cfSD_csrf.h);
    if (extra) Object.keys(extra).forEach(function(k) { p.append(k, extra[k]); });
    return p;
}
function cfSDOpenNew() {
    document.getElementById('cfSDModalLabel').textContent = 'Add Data Entry';
    document.getElementById('cfSD_key').value   = '';
    document.getElementById('cfSD_value').value = '';
    document.getElementById('cfSD_key').readOnly = false;
    document.getElementById('cfSDKeyGroup').style.opacity = '1';
}
function cfSDOpenEdit(id, key, value) {
    document.getElementById('cfSDModalLabel').textContent = 'Edit Value';
    document.getElementById('cfSD_key').value   = key;
    document.getElementById('cfSD_value').value = value;
    document.getElementById('cfSD_key').readOnly = true;
    document.getElementById('cfSDKeyGroup').style.opacity = '.6';
    document.getElementById('cfSDSaveBtn').dataset.editId = id;
}
function cfSDSave() {
    var key   = document.getElementById('cfSD_key').value.trim().toLowerCase();
    var value = document.getElementById('cfSD_value').value;
    if (!key)             { alert('Data Key is required.'); return; }
    if (value.trim() === '') { alert('Value is required.'); return; }
    var btn = document.getElementById('cfSDSaveBtn');
    btn.disabled = true;
    _cfSDPost('<?php echo admin_url('pitchsnap/site_data_save'); ?>', _cfSDParams({
        site_id:  _cfSD_siteId,
        data_key: key,
        value:    value
    })).then(function(d) {
        if (d.success) { location.reload(); }
        else { alert(d.message || 'Error saving.'); btn.disabled = false; }
    }).catch(function() { alert('Request failed.'); btn.disabled = false; });
}
function cfSDDelete(id, key) {
    if (!confirm('Delete "' + key + '"? This cannot be undone.')) return;
    _cfSDPost('<?php echo admin_url('pitchsnap/site_data_delete/'); ?>' + id, _cfSDParams())
        .then(function(d) {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Could not delete.'); }
        }).catch(function() {});
}
</script>
