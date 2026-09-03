<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$CI         =& get_instance();
$csrf_n     = $CI->security->get_csrf_token_name();
$csrf_h     = $CI->security->get_csrf_hash();
$section_id = (int) $section['id'];
$flow_id    = $flow ? (int) $flow['id'] : 0;

// Build flow question sequence map for JS and for seq_index in cfOpenEdit calls
$seq_map      = [];
$flow_q_for_js = [];
foreach ($flow_questions as $_si => $_sq) {
    $seq_map[$_sq['id']] = $_si;
    $flow_q_for_js[] = ['id' => (int) $_sq['id'], 'label' => $_sq['label'], 'data_key' => (string) ($_sq['data_key'] ?? '')];
}

// Build per-question data map for JS (avoids double-quote injection in onclick attrs)
$_q_data_for_js = [];
foreach ($questions as $_q) {
    $_q_data_for_js[(int) $_q['id']] = [
        'id'                    => (int) $_q['id'],
        'label'                 => (string) $_q['label'],
        'help_text'             => (string) ($_q['help_text'] ?? ''),
        'field_type'            => (string) $_q['field_type'],
        'required'              => (int) $_q['required'],
        'options_json'          => $_q['options_json'] ?? null,
        'extraction_map_json'   => $_q['extraction_map_json'] ?? null,
        'data_key'              => (string) ($_q['data_key'] ?? ''),
        'purpose'               => (string) ($_q['purpose'] ?? 'data'),
        'condition_question_id' => (int) ($_q['condition_question_id'] ?? 0),
        'condition_operator'    => (string) ($_q['condition_operator'] ?? ''),
        'condition_value'       => (string) ($_q['condition_value'] ?? ''),
        'seq_index'             => $seq_map[$_q['id']] ?? 9999,
        'tag_ids'               => array_map('intval', array_column($_q['usage_tags'] ?? [], 'id')),
    ];
}

$type_labels = [
    'text'     => 'Text',
    'textarea' => 'Text Area',
    'number'   => 'Number',
    'email'    => 'Email',
    'phone'    => 'Phone',
    'url'      => 'URL',
    'select'   => 'Dropdown',
    'radio'    => 'Radio',
    'checkbox' => 'Checkbox',
    'yes_no'           => 'Yes / No',
    'question_builder' => 'Question Builder',
    'file'             => 'File Upload',
    'phone_number_picker' => 'Phone Number Picker',
];
?>
<?php init_head(); ?>
<style>
tr.cf-dragging { opacity: .4; }
tr.cf-drag-over td { background: #d9edf7 !important; }
td.cf-handle { cursor: grab; color: #aaa; width: 24px; text-align: center; }
</style>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <ol class="breadcrumb">
                    <li><a href="<?php echo admin_url('pitchsnap/flows'); ?>">Onboarding Flows</a></li>
                    <?php if ($flow) { ?>
                    <li><a href="<?php echo admin_url('pitchsnap/flow_sections/' . $flow_id); ?>"><?php echo e($flow['name']); ?> — Sections</a></li>
                    <?php } ?>
                    <li class="active"><?php echo e($section['name']); ?> — Questions</li>
                </ol>

                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                            <h4 class="tw-font-semibold tw-mb-0">Questions: <?php echo e($section['name']); ?></h4>
                            <button type="button" class="btn btn-primary btn-sm"
                                onclick="cfOpenNew()" data-toggle="modal" data-target="#cfQModal">
                                <i class="fa fa-plus"></i> Add Question
                            </button>
                        </div>

                        <?php if (empty($questions)) { ?>
                        <p class="text-muted">No questions yet. Add one to get started.</p>
                        <?php } else { ?>
                        <p class="text-muted" style="font-size:12px;margin-bottom:8px;"><i class="fa fa-arrows-v"></i> Drag rows to reorder.</p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th style="width:24px;"></th>
                                        <th>Label</th>
                                        <th>Type</th>
                                        <th>Required</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="cfQBody">
                                    <?php foreach ($questions as $q) { ?>
                                    <tr draggable="true" data-id="<?php echo (int) $q['id']; ?>">
                                        <td class="cf-handle"><i class="fa fa-bars"></i></td>
                                        <td>
                                            <?php echo e($q['label']); ?>
                                            <?php if (!empty($q['data_key'])) { ?>
                                            <br><code style="font-size:11px;color:#777;"><?php echo e($q['data_key']); ?></code>
                                            <?php } ?>
                                            <?php if ($q['help_text']) { ?>
                                            <br><small class="text-muted"><?php echo e($q['help_text']); ?></small>
                                            <?php } ?>
                                            <?php if (!empty($q['condition_question_id'])) { ?>
                                            <br><span class="label label-default" style="font-size:10px;"><i class="fa fa-code-fork"></i> Conditional</span>
                                            <?php } ?>
                                            <?php if (!empty($q['usage_tags'])) { ?>
                                            <br><?php foreach ($q['usage_tags'] as $_utag) { ?><span class="label label-info" style="font-size:10px;margin-right:2px;"><?php echo e($_utag['name']); ?></span><?php } ?>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <?php echo e($type_labels[$q['field_type']] ?? $q['field_type']); ?>
                                            <?php if (!empty($q['purpose']) && $q['purpose'] !== 'data') { ?>
                                            <br><span class="label label-info" style="font-size:10px;"><?php echo e($q['purpose']); ?></span>
                                            <?php } ?>
                                        </td>
                                        <td><?php echo $q['required'] ? '<span class="label label-warning">Required</span>' : '<span class="label label-default">Optional</span>'; ?></td>
                                        <td class="text-right" style="white-space:nowrap;">
                                            <button type="button" class="btn btn-default btn-xs"
                                                onclick="cfOpenEdit(<?php echo (int) $q['id']; ?>)"
                                                data-toggle="modal" data-target="#cfQModal">
                                                <i class="fa fa-pencil"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-danger btn-xs"
                                                onclick="cfDeleteQ(<?php echo (int) $q['id']; ?>, <?php echo htmlspecialchars(json_encode($q['label']), ENT_QUOTES); ?>)">
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
<div class="modal fade" id="cfQModal" tabindex="-1" role="dialog" aria-labelledby="cfQModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="cfQModalLabel">New Question</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cf_q_id" value="">
                <div class="form-group">
                    <label>Label <span class="text-danger">*</span></label>
                    <input type="text" id="cf_q_label" class="form-control" placeholder="e.g. Business Name">
                </div>
                <div class="form-group">
                    <label>Help Text</label>
                    <input type="text" id="cf_q_help" class="form-control" placeholder="Optional hint shown to the customer">
                </div>
                <div class="form-group">
                    <label>Field Type</label>
                    <select id="cf_q_type" class="form-control" onchange="cfTypeChange()">
                        <option value="text">Text</option>
                        <option value="textarea">Text Area</option>
                        <option value="number">Number</option>
                        <option value="email">Email</option>
                        <option value="phone">Phone</option>
                        <option value="url">URL</option>
                        <option value="select">Dropdown (Select)</option>
                        <option value="radio">Radio</option>
                        <option value="checkbox">Checkbox</option>
                        <option value="yes_no">Yes / No</option>
                        <option value="question_builder">Question Builder</option>
                        <option value="file">File Upload</option>
                        <option value="phone_number_picker">Phone Number Picker</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Data Key <span class="text-danger">*</span></label>
                    <input type="text" id="cf_q_data_key" class="form-control" placeholder="e.g. business.name" style="font-family:monospace;">
                    <p class="help-block" style="margin-top:4px;font-size:11px;">Lowercase letters, numbers, underscores, dots. Must be unique.</p>
                </div>
                <div class="form-group">
                    <label>Purpose</label>
                    <select id="cf_q_purpose" class="form-control">
                        <option value="data">Data (default)</option>
                        <option value="quote_form_definition">Quote Form Definition</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="cf_q_required" value="1"> Required
                    </label>
                </div>
                <div id="cfOptionsSection" style="display:none;">
                    <label>Options <span class="text-danger">*</span></label>
                    <div id="cfOptionsList" style="margin-bottom:6px;"></div>
                    <button type="button" class="btn btn-default btn-xs" onclick="cfAddOption('')">
                        <i class="fa fa-plus"></i> Add Option
                    </button>
                </div>
                <div id="cfExtractionSection" style="display:none;">
                    <hr style="margin:12px 0;">
                    <div class="form-group" style="margin-bottom:6px;">
                        <label><input type="checkbox" id="cf_q_extract_enabled" onchange="cfExtractToggle()"> Map file contents to prefill fields</label>
                    </div>
                    <div id="cfExtractMappings" style="display:none;">
                        <p class="help-block" style="font-size:11px;margin-bottom:8px;">AI will read the uploaded document and write extracted values to the selected fields. Existing non-empty values are never overwritten.</p>
                        <div id="cfExtractRows"></div>
                        <button type="button" class="btn btn-default btn-xs" onclick="cfAddExtractRow('','')">
                            <i class="fa fa-plus"></i> Add Mapping
                        </button>
                    </div>
                </div>
                <hr style="margin:12px 0;">
                <div class="form-group" style="margin-bottom:6px;">
                    <label><input type="checkbox" id="cf_q_cond_enabled" onchange="cfCondToggle()"> Show this question conditionally</label>
                </div>
                <div id="cfCondSection" style="display:none;">
                    <div class="form-group">
                        <label>Controlling Question</label>
                        <select id="cf_q_ctrl_id" class="form-control">
                            <option value="">— select a question —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Operator</label>
                        <select id="cf_q_ctrl_op" class="form-control">
                            <option value="equals">Equals</option>
                            <option value="not_equals">Not Equals</option>
                            <option value="contains">Contains</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Value</label>
                        <input type="text" id="cf_q_ctrl_val" class="form-control" placeholder="e.g. yes">
                    </div>
                </div>
                <?php if (!empty($usage_tags)) { ?>
                <hr style="margin:12px 0;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Usage Tags</label>
                    <div id="cfTagCheckboxes" style="margin-top:6px;">
                        <?php foreach ($usage_tags as $_utag) { ?>
                        <label style="font-weight:normal;display:block;margin-bottom:4px;">
                            <input type="checkbox" class="cf-tag-cb" value="<?php echo (int) $_utag['id']; ?>">
                            <?php echo e($_utag['name']); ?>
                            <code style="font-size:11px;color:#999;margin-left:4px;"><?php echo e($_utag['slug']); ?></code>
                        </label>
                        <?php } ?>
                    </div>
                    <p class="help-block" style="font-size:11px;margin-top:6px;">Which systems may use this answer. Tags are labels only — no actions are triggered.</p>
                </div>
                <?php } ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="cfQSaveBtn" onclick="cfSave()">Save</button>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
<script>
var _cf_csrf        = {n: '<?php echo $csrf_n; ?>', h: '<?php echo $csrf_h; ?>'};
var _cf_section_id  = <?php echo $section_id; ?>;
var _cfOptionTypes  = ['select','radio','checkbox'];
var _cfDragSrc      = null;
var _cfFlowQuestions = <?php echo json_encode($flow_q_for_js); ?>;
var _cfQData        = <?php echo json_encode($_q_data_for_js); ?>;
var _cfExtractionFields = [
    {key: 'business_name',  label: 'Legal Business Name'},
    {key: 'ein',            label: 'EIN'},
    {key: 'street_address', label: 'Street Address'},
    {key: 'city',           label: 'City'},
    {key: 'state',          label: 'State'},
    {key: 'postal_code',    label: 'ZIP / Postal Code'},
];

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

/* ── Modal helpers ── */
function cfTypeChange() {
    var t = document.getElementById('cf_q_type').value;
    document.getElementById('cfOptionsSection').style.display = _cfOptionTypes.indexOf(t) !== -1 ? '' : 'none';
    document.getElementById('cfExtractionSection').style.display = t === 'file' ? '' : 'none';
    if (t !== 'file') {
        document.getElementById('cf_q_extract_enabled').checked = false;
        document.getElementById('cfExtractMappings').style.display = 'none';
    }
}

function cfExtractToggle() {
    document.getElementById('cfExtractMappings').style.display =
        document.getElementById('cf_q_extract_enabled').checked ? '' : 'none';
}

function cfAddExtractRow(extractionField, dataKey) {
    var container = document.getElementById('cfExtractRows');
    var row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:4px;margin-bottom:4px;align-items:center;';

    var efSel = '<select class="form-control cf-ef-sel" style="flex:1;">';
    efSel += '<option value="">— field —</option>';
    _cfExtractionFields.forEach(function(f) {
        efSel += '<option value="' + f.key + '"' + (f.key === extractionField ? ' selected' : '') + '>' + f.label + '</option>';
    });
    efSel += '</select>';

    var dkSel = '<select class="form-control cf-dk-sel" style="flex:1;">';
    dkSel += '<option value="">— target data_key —</option>';
    _cfFlowQuestions.forEach(function(q) {
        if (!q.data_key) { return; }
        dkSel += '<option value="' + _esc(q.data_key) + '"' + (q.data_key === dataKey ? ' selected' : '') + '>' + _esc(q.data_key) + '</option>';
    });
    dkSel += '</select>';

    row.innerHTML = efSel + '<span style="line-height:34px;color:#999;">&rarr;</span>' + dkSel
        + '<button type="button" class="btn btn-default btn-xs" onclick="this.parentNode.remove()" style="flex-shrink:0;"><i class="fa fa-times"></i></button>';
    container.appendChild(row);
}

function cfAddOption(val) {
    var list = document.getElementById('cfOptionsList');
    var row  = document.createElement('div');
    row.style.cssText = 'display:flex;gap:4px;margin-bottom:4px;';
    row.innerHTML = '<input type="text" class="form-control cf-opt" value="' + _esc(val) + '" placeholder="Option label">'
                  + '<button type="button" class="btn btn-default btn-xs" onclick="this.parentNode.remove()" style="flex-shrink:0;"><i class="fa fa-times"></i></button>';
    list.appendChild(row);
}

function _esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

function cfGetOptions() {
    return Array.from(document.querySelectorAll('#cfOptionsList .cf-opt'))
                .map(function(i) { return i.value.trim(); })
                .filter(function(v) { return v !== ''; });
}

function cfCondToggle() {
    document.getElementById('cfCondSection').style.display =
        document.getElementById('cf_q_cond_enabled').checked ? '' : 'none';
}

function cfPopulateControllers(excludeId, maxSeqIndex) {
    var sel = document.getElementById('cf_q_ctrl_id');
    sel.innerHTML = '<option value="">— select a question —</option>';
    _cfFlowQuestions.forEach(function(q, i) {
        if (q.id === excludeId) { return; }
        if (maxSeqIndex !== null && i >= maxSeqIndex) { return; }
        var opt = document.createElement('option');
        opt.value = q.id;
        opt.textContent = (q.data_key ? '[' + q.data_key + '] ' : '') + q.label;
        sel.appendChild(opt);
    });
}

function cfOpenNew() {
    document.getElementById('cfQModalLabel').textContent = 'New Question';
    document.getElementById('cf_q_id').value = '';
    document.getElementById('cf_q_label').value = '';
    document.getElementById('cf_q_help').value = '';
    document.getElementById('cf_q_type').value = 'text';
    document.getElementById('cf_q_data_key').value = '';
    document.getElementById('cf_q_purpose').value = 'data';
    document.getElementById('cf_q_required').checked = false;
    document.getElementById('cfOptionsList').innerHTML = '';
    document.getElementById('cfOptionsSection').style.display = 'none';
    document.getElementById('cfExtractionSection').style.display = 'none';
    document.getElementById('cf_q_extract_enabled').checked = false;
    document.getElementById('cfExtractMappings').style.display = 'none';
    document.getElementById('cfExtractRows').innerHTML = '';
    document.getElementById('cf_q_cond_enabled').checked = false;
    document.getElementById('cfCondSection').style.display = 'none';
    document.getElementById('cf_q_ctrl_op').value = 'equals';
    document.getElementById('cf_q_ctrl_val').value = '';
    cfPopulateControllers(0, null);
    document.querySelectorAll('#cfTagCheckboxes .cf-tag-cb').forEach(function(cb) { cb.checked = false; });
}

function cfOpenEdit(id) {
    var q = _cfQData[id];
    if (!q) { return; }
    document.getElementById('cfQModalLabel').textContent = 'Edit Question';
    document.getElementById('cf_q_id').value = q.id;
    document.getElementById('cf_q_label').value = q.label || '';
    document.getElementById('cf_q_help').value = q.help_text || '';
    document.getElementById('cf_q_type').value = q.field_type || 'text';
    document.getElementById('cf_q_data_key').value = q.data_key || '';
    document.getElementById('cf_q_purpose').value = q.purpose || 'data';
    document.getElementById('cf_q_required').checked = q.required === 1;
    document.getElementById('cfOptionsList').innerHTML = '';
    var showOpts = _cfOptionTypes.indexOf(q.field_type) !== -1;
    document.getElementById('cfOptionsSection').style.display = showOpts ? '' : 'none';
    if (showOpts && q.options_json) {
        try {
            var opts = JSON.parse(q.options_json);
            if (Array.isArray(opts)) { opts.forEach(function(o) { cfAddOption(o); }); }
        } catch(e) {}
    }
    // Extraction mapping
    var isFile = q.field_type === 'file';
    document.getElementById('cfExtractionSection').style.display = isFile ? '' : 'none';
    document.getElementById('cfExtractRows').innerHTML = '';
    var hasMap = false;
    if (isFile && q.extraction_map_json) {
        try {
            var exMap = JSON.parse(q.extraction_map_json);
            if (Array.isArray(exMap) && exMap.length) {
                hasMap = true;
                exMap.forEach(function(row) { cfAddExtractRow(row.extraction_field || '', row.data_key || ''); });
            }
        } catch(e) {}
    }
    document.getElementById('cf_q_extract_enabled').checked = hasMap;
    document.getElementById('cfExtractMappings').style.display = hasMap ? '' : 'none';
    cfPopulateControllers(q.id, q.seq_index);
    var hasCond = q.condition_question_id > 0;
    document.getElementById('cf_q_cond_enabled').checked = hasCond;
    document.getElementById('cfCondSection').style.display = hasCond ? '' : 'none';
    document.getElementById('cf_q_ctrl_id').value = hasCond ? q.condition_question_id : '';
    document.getElementById('cf_q_ctrl_op').value = q.condition_operator || 'equals';
    document.getElementById('cf_q_ctrl_val').value = q.condition_value || '';
    var tagIds = Array.isArray(q.tag_ids) ? q.tag_ids.map(Number) : [];
    document.querySelectorAll('#cfTagCheckboxes .cf-tag-cb').forEach(function(cb) {
        cb.checked = tagIds.indexOf(Number(cb.value)) !== -1;
    });
}

/* Auto-suggest data_key from label when key is empty */
document.getElementById('cf_q_label').addEventListener('blur', function() {
    var keyField = document.getElementById('cf_q_data_key');
    if (keyField.value !== '') { return; }
    var slug = this.value.trim().toLowerCase().replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, '_');
    if (slug) { keyField.value = slug; }
});

function cfSave() {
    var label   = document.getElementById('cf_q_label').value.trim();
    var dataKey = document.getElementById('cf_q_data_key').value.trim().toLowerCase();
    if (!label)   { alert('Label is required.'); return; }
    if (!dataKey) { alert('Data Key is required.'); return; }
    var type = document.getElementById('cf_q_type').value;
    var optionsJson = 'null';
    if (_cfOptionTypes.indexOf(type) !== -1) {
        var opts = cfGetOptions();
        if (!opts.length) { alert('Add at least one option for this field type.'); return; }
        optionsJson = JSON.stringify(opts);
    }
    var condEnabled = document.getElementById('cf_q_cond_enabled').checked;
    var ctrlId = '', ctrlOp = '', ctrlVal = '';
    if (condEnabled) {
        ctrlId  = document.getElementById('cf_q_ctrl_id').value;
        ctrlOp  = document.getElementById('cf_q_ctrl_op').value;
        ctrlVal = document.getElementById('cf_q_ctrl_val').value.trim();
        if (!ctrlId)  { alert('Select a controlling question.'); return; }
        if (!ctrlVal) { alert('Condition value is required.'); return; }
    }
    // Collect extraction mappings if applicable
    var extractionMapJson = 'null';
    if (type === 'file' && document.getElementById('cf_q_extract_enabled').checked) {
        var exRows = [];
        document.querySelectorAll('#cfExtractRows').forEach(function(container) {
            container.querySelectorAll('div').forEach(function(row) {
                var ef = row.querySelector('.cf-ef-sel');
                var dk = row.querySelector('.cf-dk-sel');
                if (ef && dk && ef.value && dk.value) {
                    exRows.push({extraction_field: ef.value, data_key: dk.value});
                }
            });
        });
        if (exRows.length) { extractionMapJson = JSON.stringify(exRows); }
    }

    var btn = document.getElementById('cfQSaveBtn');
    btn.disabled = true;
    var p = _cfParams({
        id:                    document.getElementById('cf_q_id').value,
        section_id:            _cf_section_id,
        label:                 label,
        data_key:              dataKey,
        purpose:               document.getElementById('cf_q_purpose').value,
        help_text:             document.getElementById('cf_q_help').value,
        field_type:            type,
        required:              document.getElementById('cf_q_required').checked ? '1' : '0',
        options_json:          optionsJson,
        extraction_map_json:   extractionMapJson,
        condition_question_id: condEnabled ? ctrlId : '',
        condition_operator:    condEnabled ? ctrlOp : '',
        condition_value:       condEnabled ? ctrlVal : ''
    });
    document.querySelectorAll('#cfTagCheckboxes .cf-tag-cb:checked').forEach(function(cb) {
        p.append('tag_ids[]', cb.value);
    });
    _cfPost('<?php echo admin_url('pitchsnap/question_save'); ?>', p)
        .then(function(d) {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Error saving question.'); btn.disabled = false; }
        }).catch(function() { alert('Request failed.'); btn.disabled = false; });
}

function cfDeleteQ(id, label) {
    if (!confirm('Delete question "' + label + '"? This cannot be undone.')) { return; }
    _cfPost('<?php echo admin_url('pitchsnap/question_delete/'); ?>' + id, _cfParams())
        .then(function(d) {
            if (d.success) { location.reload(); }
            else { alert(d.message || 'Could not delete question.'); }
        }).catch(function() {});
}

/* ── Drag-and-drop reorder ── */
function cfInitDrag() {
    var tbody = document.getElementById('cfQBody');
    if (!tbody) { return; }
    Array.from(tbody.querySelectorAll('tr[draggable]')).forEach(function(row) {
        row.addEventListener('dragstart', function(e) {
            _cfDragSrc = this;
            e.dataTransfer.effectAllowed = 'move';
            this.classList.add('cf-dragging');
        });
        row.addEventListener('dragend', function() {
            this.classList.remove('cf-dragging');
            Array.from(tbody.querySelectorAll('tr')).forEach(function(r) { r.classList.remove('cf-drag-over'); });
        });
        row.addEventListener('dragover', function(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
        row.addEventListener('dragenter', function() { this.classList.add('cf-drag-over'); });
        row.addEventListener('dragleave', function() { this.classList.remove('cf-drag-over'); });
        row.addEventListener('drop', function(e) {
            e.stopPropagation();
            if (_cfDragSrc && _cfDragSrc !== this) {
                var rows = Array.from(tbody.querySelectorAll('tr[draggable]'));
                var si   = rows.indexOf(_cfDragSrc);
                var di   = rows.indexOf(this);
                if (si < di) { tbody.insertBefore(_cfDragSrc, this.nextSibling); }
                else         { tbody.insertBefore(_cfDragSrc, this); }
                cfSaveOrder();
            }
        });
    });
}

function cfSaveOrder() {
    var rows = document.querySelectorAll('#cfQBody tr[draggable]');
    var p    = new URLSearchParams();
    p.append(_cf_csrf.n, _cf_csrf.h);
    p.append('section_id', _cf_section_id);
    rows.forEach(function(r) { p.append('ids[]', r.dataset.id); });
    fetch('<?php echo admin_url('pitchsnap/question_reorder'); ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: p
    }).then(function(r) { return r.json(); })
      .then(function(d) {
          if (!d.success) {
              alert(d.message || 'Reorder rejected. Restoring previous order.');
              location.reload();
          }
      }).catch(function() {});
}

cfInitDrag();
</script>
