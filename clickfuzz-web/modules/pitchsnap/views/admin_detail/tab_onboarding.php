<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$_csrf_n  = $this->security->get_csrf_token_name();
$_csrf_h  = $this->security->get_csrf_hash();
$_site_id = !empty($site) ? (int) $site->id : 0;
$_active_flows = array_filter($all_flows ?? [], function($f) { return $f['status'] === 'active'; });
$_ob_global_base = rtrim(get_option('pitchsnap_onboarding_page_url') ?: base_url('pitchsnap/onboarding_embed'), '/');
?>
<!-- ══════════════════════════════════════════════════════════
     TAB — ONBOARDING LINKS
     ══════════════════════════════════════════════════════════ -->
<div role="tabpanel" class="tab-pane" id="tab-onboarding">
<script>
var _cfOB_csrf   = {n: '<?php echo $_csrf_n; ?>', h: '<?php echo $_csrf_h; ?>'};
var _cfOB_siteId = <?php echo $_site_id; ?>;
</script>

<?php if (!$_site_id) { ?>
    <p class="text-muted">No site record exists for this website yet.</p>
<?php } else { ?>
    <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
        <h4 class="tw-font-semibold tw-mb-0">Onboarding Links</h4>
        <?php if (!empty($_active_flows)) { ?>
        <button type="button" class="btn btn-primary btn-sm"
            onclick="cfOBOpenNew()" data-toggle="modal" data-target="#cfOBModal">
            <i class="fa fa-plus"></i> Create Link
        </button>
        <?php } ?>
    </div>
    <p class="text-muted" style="margin-bottom:16px;font-size:13px;">Each link is a unique, token-protected URL sent to the customer to complete an onboarding flow.</p>

    <?php if (empty($onboarding_links)) { ?>
    <p class="text-muted">No onboarding links yet.</p>
    <?php } else { ?>
    <div class="table-responsive">
        <table class="table table-bordered table-hover table-condensed">
            <thead>
                <tr>
                    <th>Flow</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Completed</th>
                    <th>Link</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($onboarding_links as $_lnk) { ?>
                <?php
                    $_flow_base = !empty($_lnk['flow_page_url']) ? rtrim($_lnk['flow_page_url'], '/') : $_ob_global_base;
                    $_url = $_flow_base . '?token=' . $_lnk['token'];
                ?>
                <tr>
                    <td><?php echo e($_lnk['flow_name'] ?: '—'); ?></td>
                    <td>
                        <?php if ($_lnk['status'] === 'active') { ?>
                        <span class="label label-success">Active</span>
                        <?php } elseif ($_lnk['status'] === 'completed') { ?>
                        <span class="label label-primary" title="Submitted — still editable until revoked">Completed</span>
                        <?php } else { ?>
                        <span class="label label-default">Revoked</span>
                        <?php } ?>
                    </td>
                    <td><small class="text-muted"><?php echo e($_lnk['created_at']); ?></small></td>
                    <td><small class="text-muted"><?php echo !empty($_lnk['completed_at']) ? e($_lnk['completed_at']) : '—'; ?></small></td>
                    <td style="max-width:300px;">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" value="<?php echo e($_url); ?>" readonly onclick="this.select()">
                            <span class="input-group-btn">
                                <button class="btn btn-default btn-sm" type="button"
                                    data-copy-url="<?php echo e($_url); ?>"
                                    onclick="cfOBCopy(this)" title="Copy">
                                    <i class="fa fa-copy"></i>
                                </button>
                            </span>
                        </div>
                    </td>
                    <td class="text-right" style="white-space:nowrap;">
                        <?php if ($_lnk['status'] === 'active' || $_lnk['status'] === 'completed') { ?>
                        <button type="button" class="btn btn-warning btn-xs"
                            onclick="cfOBRevoke(<?php echo (int) $_lnk['id']; ?>)">
                            Revoke
                        </button>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>

    <?php if (empty($_active_flows)) { ?>
    <p class="text-muted" style="font-size:12px;"><i class="fa fa-info-circle"></i> No active onboarding flows available. Create one under <a href="<?php echo admin_url('pitchsnap/flows'); ?>">Onboarding Flows</a>.</p>
    <?php } ?>
<?php } ?>
</div><!-- #tab-onboarding -->

<!-- Create Link Modal -->
<div class="modal fade" id="cfOBModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">Create Onboarding Link</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Onboarding Flow <span class="text-danger">*</span></label>
                    <select id="cfOB_flow" class="form-control">
                        <option value="">— select a flow —</option>
                        <?php foreach ($_active_flows as $_f) { ?>
                        <option value="<?php echo (int) $_f['id']; ?>"><?php echo e($_f['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div id="cfOB_result" style="display:none;margin-top:12px;">
                    <label>Link created — copy and send to customer:</label>
                    <div class="input-group">
                        <input type="text" id="cfOB_result_url" class="form-control" readonly onclick="this.select()">
                        <span class="input-group-btn">
                            <button class="btn btn-default" type="button" onclick="cfOBCopyResult()"><i class="fa fa-copy"></i> Copy</button>
                        </span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal" id="cfOB_cancelBtn">Cancel</button>
                <button type="button" class="btn btn-primary" id="cfOB_createBtn" onclick="cfOBCreate()">Create Link</button>
            </div>
        </div>
    </div>
</div>

<script>
function _cfOBPost(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: body
    }).then(function(r) { return r.json(); });
}
function _cfOBParams(extra) {
    var p = new URLSearchParams();
    p.append(_cfOB_csrf.n, _cfOB_csrf.h);
    if (extra) Object.keys(extra).forEach(function(k) { p.append(k, extra[k]); });
    return p;
}
function cfOBOpenNew() {
    document.getElementById('cfOB_flow').value = '';
    document.getElementById('cfOB_result').style.display = 'none';
    document.getElementById('cfOB_result_url').value = '';
    document.getElementById('cfOB_createBtn').style.display = '';
    document.getElementById('cfOB_cancelBtn').textContent = 'Cancel';
}
function cfOBCreate() {
    var flow = document.getElementById('cfOB_flow').value;
    if (!flow) { alert('Select a flow.'); return; }
    var btn = document.getElementById('cfOB_createBtn');
    btn.disabled = true;
    _cfOBPost('<?php echo admin_url('pitchsnap/onboarding_link_create'); ?>', _cfOBParams({
        site_id: _cfOB_siteId,
        flow_id: flow
    })).then(function(d) {
        btn.disabled = false;
        if (d.success) {
            document.getElementById('cfOB_result_url').value = d.url;
            document.getElementById('cfOB_result').style.display = '';
            btn.style.display = 'none';
            document.getElementById('cfOB_cancelBtn').textContent = 'Done';
            location.reload();
        } else {
            alert(d.message || 'Error creating link.');
        }
    }).catch(function() { btn.disabled = false; alert('Request failed.'); });
}
function cfOBRevoke(id) {
    if (!confirm('Revoke this onboarding link? The customer will no longer be able to use it.')) return;
    _cfOBPost('<?php echo admin_url('pitchsnap/onboarding_link_revoke/'); ?>' + id, _cfOBParams())
        .then(function(d) {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Could not revoke.'); }
        }).catch(function() {});
}
function cfOBCopy(btn, url) {
    var target = url || (btn && btn.getAttribute('data-copy-url')) || '';
    navigator.clipboard.writeText(target).then(function() {
        if (btn) {
            var orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-check"></i>';
            setTimeout(function() { btn.innerHTML = orig; }, 1500);
        }
    }).catch(function() { prompt('Copy this link:', target); });
}
function cfOBCopyResult() {
    var url = document.getElementById('cfOB_result_url').value;
    cfOBCopy(document.querySelector('#cfOBModal .input-group-btn button'), url);
}
</script>
