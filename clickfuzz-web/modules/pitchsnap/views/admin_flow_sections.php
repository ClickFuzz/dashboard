<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$CI      =& get_instance();
$csrf_n  = $CI->security->get_csrf_token_name();
$csrf_h  = $CI->security->get_csrf_hash();
$flow_id = (int) $flow['id'];
$count   = count($sections);
?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <ol class="breadcrumb">
                    <li><a href="<?php echo admin_url('pitchsnap/flows'); ?>">Onboarding Flows</a></li>
                    <li class="active"><?php echo e($flow['name']); ?> — Sections</li>
                </ol>

                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                            <h4 class="tw-font-semibold tw-mb-0">Sections: <?php echo e($flow['name']); ?></h4>
                            <button type="button" class="btn btn-primary btn-sm"
                                onclick="cfOpenNew()" data-toggle="modal" data-target="#cfSectionModal">
                                <i class="fa fa-plus"></i> Add Section
                            </button>
                        </div>

                        <!-- ── Form page URL ──────────────────────────────── -->
                        <?php $_has_page_url = !empty($flow['page_url']); ?>
                        <div style="margin-bottom:20px;">
                            <div id="cfPageUrlDisplay"
                                style="display:<?php echo $_has_page_url ? 'flex' : 'none'; ?>;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span class="text-muted" style="font-size:12px;">Form page:</span>
                                <a id="cfPageUrlLink" href="<?php echo e($flow['page_url'] ?? ''); ?>"
                                    target="_blank" rel="noopener noreferrer"
                                    style="font-size:13px;"><?php echo e($flow['page_url'] ?? ''); ?></a>
                                <button type="button" class="btn btn-default btn-xs" onclick="cfPageUrlEdit()">
                                    <i class="fa fa-pencil"></i> Change
                                </button>
                            </div>
                            <div id="cfPageUrlEdit" style="display:<?php echo $_has_page_url ? 'none' : 'block'; ?>;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span class="text-muted" style="font-size:12px;white-space:nowrap;">Form page URL:</span>
                                    <input type="url" id="cfPageUrlInput" class="form-control input-sm"
                                        style="width:360px;max-width:100%;"
                                        placeholder="https://example.com/onboarding/"
                                        value="<?php echo e($flow['page_url'] ?? ''); ?>">
                                    <button type="button" id="cfPageUrlSaveBtn" class="btn btn-primary btn-sm" onclick="cfPageUrlSave()">Save</button>
                                    <button type="button" id="cfPageUrlCancelBtn" class="btn btn-default btn-sm"
                                        style="display:<?php echo $_has_page_url ? 'inline-block' : 'none'; ?>;"
                                        onclick="cfPageUrlCancel()">Cancel</button>
                                </div>
                                <p class="text-muted" style="font-size:12px;margin:4px 0 0;">
                                    The WordPress page where this form is displayed. Used to generate onboarding link URLs.
                                </p>
                            </div>
                        </div>
                        <!-- ── / Form page URL ────────────────────────────── -->

<script>
var _cfPageUrlEndpoint = '<?php echo admin_url('pitchsnap/flow_save_page_url/' . $flow_id); ?>';
var _cfPageUrlCsrfN    = '<?php echo $csrf_n; ?>';
var _cfPageUrlCsrfH    = '<?php echo $csrf_h; ?>';

function cfPageUrlEdit() {
    document.getElementById('cfPageUrlDisplay').style.display = 'none';
    document.getElementById('cfPageUrlEdit').style.display    = 'block';
    document.getElementById('cfPageUrlCancelBtn').style.display = 'inline-block';
}
function cfPageUrlCancel() {
    document.getElementById('cfPageUrlEdit').style.display    = 'none';
    document.getElementById('cfPageUrlDisplay').style.display = 'flex';
}
function cfPageUrlSave() {
    var url = document.getElementById('cfPageUrlInput').value.trim();
    var btn = document.getElementById('cfPageUrlSaveBtn');
    btn.disabled = true;
    var p = new URLSearchParams();
    p.append(_cfPageUrlCsrfN, _cfPageUrlCsrfH);
    p.append('page_url', url);
    fetch(_cfPageUrlEndpoint, {
        method:  'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body:    p.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        btn.disabled = false;
        if (!d.success) { alert(d.message || 'Could not save URL.'); return; }
        var link = document.getElementById('cfPageUrlLink');
        if (url) {
            link.href = link.textContent = url;
            document.getElementById('cfPageUrlEdit').style.display    = 'none';
            document.getElementById('cfPageUrlDisplay').style.display = 'flex';
        } else {
            link.href = link.textContent = '';
            document.getElementById('cfPageUrlCancelBtn').style.display = 'none';
            document.getElementById('cfPageUrlInput').value = '';
        }
    }).catch(function() { btn.disabled = false; alert('Request failed.'); });
}
</script>

                        <?php if (empty($sections)) { ?>
                        <p class="text-muted">No sections yet. Add one to get started.</p>
                        <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th style="width:60px;" class="text-center">#</th>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sections as $i => $s) { ?>
                                    <tr>
                                        <td class="text-center"><?php echo $i + 1; ?></td>
                                        <td><?php echo e($s['name']); ?></td>
                                        <td><?php echo e($s['description'] ?: '—'); ?></td>
                                        <td class="text-right" style="white-space:nowrap;">
                                            <a href="<?php echo admin_url('pitchsnap/section_questions/' . (int) $s['id']); ?>" class="btn btn-info btn-xs">
                                                <i class="fa fa-list"></i> Questions
                                            </a>
                                            <button type="button" class="btn btn-default btn-xs"
                                                onclick="cfOpenEdit(<?php echo (int) $s['id']; ?>, <?php echo htmlspecialchars(json_encode($s['name']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string) $s['description']), ENT_QUOTES); ?>)"
                                                data-toggle="modal" data-target="#cfSectionModal">
                                                <i class="fa fa-pencil"></i> Edit
                                            </button>
                                            <?php if ($i > 0) { ?>
                                            <button type="button" class="btn btn-default btn-xs"
                                                onclick="cfMove(<?php echo (int) $s['id']; ?>, 'up', this)" title="Move up">
                                                <i class="fa fa-arrow-up"></i>
                                            </button>
                                            <?php } ?>
                                            <?php if ($i < $count - 1) { ?>
                                            <button type="button" class="btn btn-default btn-xs"
                                                onclick="cfMove(<?php echo (int) $s['id']; ?>, 'down', this)" title="Move down">
                                                <i class="fa fa-arrow-down"></i>
                                            </button>
                                            <?php } ?>
                                            <button type="button" class="btn btn-danger btn-xs"
                                                onclick="cfDeleteSection(<?php echo (int) $s['id']; ?>, <?php echo htmlspecialchars(json_encode($s['name']), ENT_QUOTES); ?>)">
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
<div class="modal fade" id="cfSectionModal" tabindex="-1" role="dialog" aria-labelledby="cfSectionModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="cfSectionModalLabel">New Section</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cf_section_id" value="">
                <div class="form-group">
                    <label>Name <span class="text-danger">*</span></label>
                    <input type="text" id="cf_section_name" class="form-control" placeholder="e.g. Business Details">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="cf_section_desc" class="form-control" rows="3" placeholder="Optional notes about this section"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="cfSectionSaveBtn" onclick="cfSave()">Save</button>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
<script>
var _cf_csrf   = {n: '<?php echo $csrf_n; ?>', h: '<?php echo $csrf_h; ?>'};
var _cf_flow_id = <?php echo $flow_id; ?>;

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
    document.getElementById('cfSectionModalLabel').textContent = 'New Section';
    document.getElementById('cf_section_id').value = '';
    document.getElementById('cf_section_name').value = '';
    document.getElementById('cf_section_desc').value = '';
}

function cfOpenEdit(id, name, desc) {
    document.getElementById('cfSectionModalLabel').textContent = 'Edit Section';
    document.getElementById('cf_section_id').value = id;
    document.getElementById('cf_section_name').value = name || '';
    document.getElementById('cf_section_desc').value = desc || '';
}

function cfSave() {
    var name = document.getElementById('cf_section_name').value.trim();
    if (!name) { alert('Name is required.'); return; }
    var btn = document.getElementById('cfSectionSaveBtn');
    btn.disabled = true;
    _cfPost('<?php echo admin_url('pitchsnap/section_save'); ?>', {
        id:          document.getElementById('cf_section_id').value,
        flow_id:     _cf_flow_id,
        name:        name,
        description: document.getElementById('cf_section_desc').value
    }).then(function(d) {
        if (d.success) { location.reload(); }
        else { alert(d.message || 'Error saving section.'); btn.disabled = false; }
    }).catch(function() { alert('Request failed.'); btn.disabled = false; });
}

function cfMove(id, direction, btn) {
    btn.disabled = true;
    _cfPost('<?php echo admin_url('pitchsnap/section_move/'); ?>' + id + '/' + direction)
        .then(function(d) {
            if (d.success) { location.reload(); }
            else { btn.disabled = false; }
        }).catch(function() { btn.disabled = false; });
}

function cfDeleteSection(id, name) {
    if (!confirm('Delete section "' + name + '" and all its questions? This cannot be undone.')) { return; }
    _cfPost('<?php echo admin_url('pitchsnap/section_delete/'); ?>' + id)
        .then(function(d) {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Could not delete section.'); }
        }).catch(function() {});
}
</script>
