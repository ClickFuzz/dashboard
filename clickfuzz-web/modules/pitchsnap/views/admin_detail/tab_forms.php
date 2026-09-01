<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
            <!-- ══════════════════════════════════════════════════════════
                 TAB — FORMS
                 ══════════════════════════════════════════════════════════ -->
            <div role="tabpanel" class="tab-pane" id="tab-forms">

                <?php if (empty($site)) { ?>
                <div class="panel_s">
                    <div class="panel-body">
                        <p class="text-muted" style="margin-bottom:0;">Forms are available after a site record is created.</p>
                    </div>
                </div>
                <?php } else {
                    $site_id = (int) $site->id;
                    $csrf_name = $this->security->get_csrf_token_name();
                    $csrf_hash = $this->security->get_csrf_hash();
                ?>

                <!-- Form list -->
                <div class="panel_s" id="cf-forms-list-panel">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between mbot15">
                            <h5 class="tw-font-semibold" style="margin:0;">Forms</h5>
                            <div>
                                <button class="btn btn-default btn-sm" onclick="cfShowCustomFields()" style="margin-right:6px;">
                                    <i class="fa fa-sliders"></i> Custom GHL Fields
                                </button>
                                <button class="btn btn-primary btn-sm" onclick="cfShowFormEditor(0)">
                                    <i class="fa fa-plus"></i> New Form
                                </button>
                            </div>
                        </div>

                        <?php if (empty($forms)) { ?>
                        <p class="text-muted">No forms yet. Default forms will be created when generation runs.</p>
                        <?php } else { ?>
                        <table class="table table-bordered table-condensed" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th width="80">Type</th>
                                    <th width="60">Fields</th>
                                    <th width="130">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($forms as $f) {
                                $f_fields   = json_decode($f->fields ?? '[]', true) ?: [];
                                $f_settings = json_decode($f->settings ?? '{}', true) ?: [];
                                $is_system  = ($f->form_type === 'system');
                            ?>
                                <tr data-form-row="<?php echo (int) $f->id; ?>">
                                    <td>
                                        <strong><?php echo e($f->name); ?></strong>
                                        <br><small class="text-muted">ID: <?php echo (int) $f->id; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($is_system) { ?>
                                        <span class="label label-info">System</span>
                                        <?php } else { ?>
                                        <span class="label label-default">Custom</span>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo count($f_fields); ?></td>
                                    <td>
                                        <button class="btn btn-info btn-xs" onclick="cfPreviewForm(<?php echo (int) $f->id; ?>)" title="Preview">
                                            <i class="fa fa-eye"></i>
                                        </button>
                                        <button class="btn btn-default btn-xs" onclick="cfShowFormEditor(<?php echo (int) $f->id; ?>)">
                                            <i class="fa fa-pencil"></i> Edit
                                        </button>
                                        <?php if (!$is_system) { ?>
                                        <button class="btn btn-danger btn-xs" onclick="cfDeleteForm(<?php echo (int) $f->id; ?>, '<?php echo addslashes(e($f->name)); ?>')">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        <?php } ?>
                    </div>
                </div>

                <!-- Custom GHL Fields panel — site-specific destination registry -->
                <div class="panel_s" id="cf-custom-fields-panel" style="display:none;">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between mbot15">
                            <h5 class="tw-font-semibold" style="margin:0;">Custom GHL Fields</h5>
                            <button class="btn btn-default btn-sm" onclick="cfHideCustomFields()">
                                <i class="fa fa-arrow-left"></i> Back to Forms
                            </button>
                        </div>
                        <p class="text-muted" style="font-size:13px;">
                            Register GHL custom field identifiers you have already created in this client's GHL sub-account.
                            Once added, they appear in this site's form builder destination dropdown.
                            Copy the field key/ID from the GHL custom field settings.
                        </p>

                        <table class="table table-bordered table-condensed" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th>Display Name</th>
                                    <th>GHL Custom Field Key</th>
                                    <th width="140">Input Mode</th>
                                    <th width="100">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="cf-custom-fields-body">
                                <tr><td colspan="4" class="text-muted">Loading…</td></tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td><input type="text" class="form-control input-sm" id="cf-new-cfd-label" placeholder="e.g. Building Size"></td>
                                    <td><input type="text" class="form-control input-sm" id="cf-new-cfd-key" placeholder="e.g. custom.abc123_field_id"></td>
                                    <td>
                                        <select class="form-control input-sm" id="cf-new-cfd-mode">
                                            <option value="single">Single Input</option>
                                            <option value="multiple">Multiple Inputs</option>
                                        </select>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary btn-xs" onclick="cfAddCustomField()">
                                            <i class="fa fa-plus"></i> Add
                                        </button>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Form editor (hidden until triggered) -->
                <div class="panel_s" id="cf-form-editor" style="display:none;">
                    <div class="panel-body">
                        <div class="tw-flex tw-items-center tw-justify-between mbot15">
                            <h5 class="tw-font-semibold" style="margin:0;" id="cf-editor-title">New Form</h5>
                            <button class="btn btn-default btn-sm" onclick="cfHideFormEditor()">
                                <i class="fa fa-times"></i> Cancel
                            </button>
                        </div>

                        <input type="hidden" id="cf-editing-form-id" value="0">

                        <div class="form-group">
                            <label>Form Name</label>
                            <input type="text" id="cf-form-name" class="form-control" placeholder="e.g. Contact Form">
                        </div>

                        <div class="form-group">
                            <label>Submit Button Label</label>
                            <input type="text" id="cf-form-submit-label" class="form-control" value="Submit">
                        </div>

                        <div class="form-group">
                            <label>Success Message</label>
                            <input type="text" id="cf-form-success-msg" class="form-control" value="Thank you! We&#039;ll be in touch soon.">
                        </div>

                        <div class="form-group">
                            <label>Fields <button type="button" class="btn btn-default btn-xs" onclick="cfAddField()" style="margin-left:8px;"><i class="fa fa-plus"></i> Add Field</button></label>
                            <div id="cf-fields-container">
                                <!-- fields rendered by JS -->
                            </div>
                        </div>

                        <button class="btn btn-primary" onclick="cfSaveForm()">
                            <i class="fa fa-save"></i> Save Form
                        </button>
                        <span id="cf-save-msg" class="text-muted" style="margin-left:10px;"></span>

                        <!-- Placements (only shown when editing existing form) -->
                        <div id="cf-placements-section" style="display:none; margin-top:24px;">
                            <hr>
                            <h5 class="tw-font-semibold mbot10">Placements</h5>
                            <p class="text-muted" style="font-size:13px;">Choose which pages display this form and how.</p>

                            <table class="table table-condensed table-bordered" id="cf-placements-table" style="margin-bottom:12px;">
                                <thead><tr><th>Page</th><th>Type</th><th width="60"></th></tr></thead>
                                <tbody id="cf-placements-body">
                                    <tr><td colspan="3" class="text-muted">Loading…</td></tr>
                                </tbody>
                            </table>

                            <div class="row">
                                <div class="col-sm-5">
                                    <select id="cf-place-page" class="form-control input-sm">
                                        <option value="">— Select page —</option>
                                        <?php foreach ($site_pages_for_forms as $sp) { ?>
                                        <option value="<?php echo (int) $sp->id; ?>"><?php echo e($sp->title ?: $sp->slug); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <select id="cf-place-type" class="form-control input-sm">
                                        <option value="inline">Inline</option>
                                        <option value="popup">Popup trigger</option>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <button class="btn btn-default btn-sm btn-block" onclick="cfAddPlacement()">
                                        <i class="fa fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form preview modal -->
                <div class="modal fade" id="cf-preview-modal" tabindex="-1" role="dialog">
                    <div class="modal-dialog modal-lg" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title" id="cf-preview-title">Form Preview</h4>
                            </div>
                            <div class="modal-body" id="cf-preview-body" style="padding:0;min-height:120px;"></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php } ?>
            </div><!-- /#tab-forms -->

<script>
(function () {
    var SITE_ID     = <?php echo (int) ($site->id ?? 0); ?>;
    var ADMIN_URL   = '<?php echo addslashes(admin_url("pitchsnap")); ?>';
    var RUNTIME_URL = '<?php echo addslashes(site_url("pitchsnap")); ?>';
    var CSRF_NAME   = '<?php echo addslashes($csrf_name ?? ""); ?>';
    var CSRF_HASH   = '<?php echo addslashes($csrf_hash ?? ""); ?>';

    // Forms data loaded from PHP
    var formsData = <?php
        $forms_js = [];
        if (!empty($forms)) {
            foreach ($forms as $ff) {
                $forms_js[] = [
                    'id'       => (int) $ff->id,
                    'name'     => $ff->name,
                    'type'     => $ff->form_type,
                    'fields'   => json_decode($ff->fields  ?? '[]', true) ?: [],
                    'settings' => json_decode($ff->settings ?? '{}', true) ?: [],
                ];
            }
        }
        echo json_encode($forms_js);
    ?>;

    var editingFormId  = 0;
    var cachedGhlDests = null; // [{id, label, ghl_key, mode, global}] loaded once per page

    function ajax(url, data, cb) {
        var csrf = {};
        csrf[CSRF_NAME] = CSRF_HASH;
        $.ajax({
            url: url, type: 'POST', dataType: 'json',
            data: $.extend({}, csrf, data),
            success: cb,
            error: function () { cb({ success: false, message: 'Request failed.' }); }
        });
    }

    // ── GHL destination loading ────────────────────────────────────────────

    function loadGhlDestinations(cb) {
        if (cachedGhlDests !== null) { cb(cachedGhlDests); return; }
        $.getJSON(ADMIN_URL + '/ghl_destinations_json/' + SITE_ID, function (r) {
            cachedGhlDests = r.success ? (r.destinations || []) : [];
            cb(cachedGhlDests);
        }).fail(function () { cachedGhlDests = []; cb([]); });
    }

    // Rebuild destination <select> for one row.
    // Single Input destinations are when already used by another row.
    // Multiple Inputs destinations are never.
    function buildDestOptions(destSelect, currentDestId, allDests) {
        var usedSingleIds = getUsedSingleDestIds(destSelect.closest('.cf-field-row'));
        destSelect.empty().append($('<option>').val('').text('— GHL Destination —'));

        var groups = { 'Single Input': [], 'Multiple Inputs': [] };
        allDests.forEach(function (d) {
            var g = d.mode === 'multiple' ? 'Multiple Inputs' : 'Single Input';
            groups[g].push(d);
        });

        ['Single Input', 'Multiple Inputs'].forEach(function (grpLabel) {
            var items = groups[grpLabel];
            if (!items.length) { return; }
            var optgroup = $('<optgroup>').attr('label', grpLabel);
            items.forEach(function (d) {
                var alreadyUsed = d.mode === 'single' && usedSingleIds.indexOf(d.id) !== -1 && d.id !== currentDestId;
                var opt = $('<option>').val(d.id).text(d.label + (d.global ? '' : ' ✦'))
                    .prop('selected', d.id === currentDestId)
                    .prop('disabled', alreadyUsed);
                if (alreadyUsed) { opt.text(d.label + ' (in use)'); }
                optgroup.append(opt);
            });
            destSelect.append(optgroup);
        });
    }

    // Return IDs of Single Input destinations already selected in other rows.
    function getUsedSingleDestIds(exceptRow) {
        if (!cachedGhlDests) { return []; }
        var usedIds = [];
        $('#cf-fields-container .cf-field-row').each(function () {
            if (exceptRow && $(this).is(exceptRow)) { return; }
            var destId = parseInt($(this).find('.cf-field-ghl').val(), 10);
            if (!destId) { return; }
            var dest = cachedGhlDests.find(function (d) { return d.id === destId; });
            if (dest && dest.mode === 'single') { usedIds.push(destId); }
        });
        return usedIds;
    }

    // Re-sync all destination dropdowns in the editor.
    function syncAllGhlDropdowns() {
        if (!cachedGhlDests) { return; }
        $('#cf-fields-container .cf-field-row').each(function () {
            var row     = $(this);
            var select  = row.find('.cf-field-ghl');
            var curId   = parseInt(select.val(), 10) || null;
            buildDestOptions(select, curId, cachedGhlDests);
        });
    }

    // ── Field row builder ──────────────────────────────────────────────────

    var FIELD_TYPES = [
        {v:'text',         t:'Text'},
        {v:'email',        t:'Email'},
        {v:'phone',        t:'Phone'},
        {v:'textarea',     t:'Textarea'},
        {v:'number',       t:'Number'},
        {v:'select',       t:'Single select'},
        {v:'multi_select', t:'Multi select'},
        {v:'date',         t:'Date'},
        {v:'checkbox',     t:'Checkbox'},
    ];

    function buildFieldRow(field, allDests) {
        field    = field || {};
        allDests = allDests || cachedGhlDests || [];
        var currentType = field.type || 'text';

        // Resolve current destination ID from stored ghl_dest_id or by matching ghl_key
        var currentDestId = field.ghl_dest_id ? parseInt(field.ghl_dest_id, 10) : null;
        if (!currentDestId && field.ghl_field && allDests.length) {
            var matched = allDests.find(function (d) { return d.ghl_key === field.ghl_field; });
            if (matched) { currentDestId = matched.id; }
        }

        var wrapper = $('<div class="cf-field-row" style="margin-bottom:8px;border:1px solid #e0e0e0;border-radius:4px;padding:6px 8px;">');
        var row1    = $('<div style="display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap;">');

        var labelInput = $('<input type="text" class="form-control input-sm cf-field-label" placeholder="Label" style="flex:1;min-width:120px;">').val(field.label || '');

        var typeSelect = $('<select class="form-control input-sm cf-field-type" style="width:130px;flex-shrink:0;">');
        FIELD_TYPES.forEach(function (opt) {
            typeSelect.append($('<option>').val(opt.v).text(opt.t).prop('selected', opt.v === currentType));
        });

        var destSelect = $('<select class="form-control input-sm cf-field-ghl" style="width:180px;flex-shrink:0;">');
        buildDestOptions(destSelect, currentDestId, allDests);

        destSelect.on('change', function () { syncAllGhlDropdowns(); });

        var reqCheck = $('<label style="white-space:nowrap;margin:0;padding-top:6px;flex-shrink:0;">')
            .append($('<input type="checkbox" class="cf-field-required">').prop('checked', !!field.required))
            .append(' Req');

        var removeBtn = $('<button type="button" class="btn btn-danger btn-xs" style="margin-top:2px;flex-shrink:0;">').html('<i class="fa fa-times"></i>')
            .on('click', function () { wrapper.remove(); syncAllGhlDropdowns(); });

        row1.append(labelInput, typeSelect, destSelect, reqCheck, removeBtn);

        // Options row — shown only for select / multi_select
        var optionsRow   = $('<div class="cf-field-options-row" style="margin-top:5px;">');
        var optionsLabel = $('<small class="text-muted" style="display:block;margin-bottom:3px;">Options (comma-separated):</small>');
        var optionsInput = $('<input type="text" class="form-control input-sm cf-field-options" placeholder="e.g. Option A, Option B, Option C">');
        if (field.options && Array.isArray(field.options)) {
            optionsInput.val(field.options.join(', '));
        }
        optionsRow.append(optionsLabel, optionsInput);

        function syncOptionsRow() {
            var t = typeSelect.val();
            optionsRow.toggle(t === 'select' || t === 'multi_select');
        }
        typeSelect.on('change', syncOptionsRow);
        syncOptionsRow();

        wrapper.append(row1, optionsRow);
        return wrapper;
    }

    window.cfAddField = function (field) {
        loadGhlDestinations(function (allDests) {
            var row = buildFieldRow(field, allDests);
            $('#cf-fields-container').append(row);
            syncAllGhlDropdowns();
        });
    };

    // ── Editor open/close ──────────────────────────────────────────────────

    window.cfShowFormEditor = function (formId) {
        editingFormId = formId;
        $('#cf-editing-form-id').val(formId);
        $('#cf-fields-container').empty();
        $('#cf-save-msg').text('');
        $('#cf-placements-section').hide();

        loadGhlDestinations(function (allDests) {
            if (formId === 0) {
                $('#cf-editor-title').text('New Form');
                $('#cf-form-name').val('');
                $('#cf-form-submit-label').val('Submit');
                $('#cf-form-success-msg').val("Thank you! We'll be in touch soon.");
            } else {
                var fd = formsData.find(function (f) { return f.id === formId; });
                if (!fd) { return; }
                $('#cf-editor-title').text('Edit: ' + fd.name);
                $('#cf-form-name').val(fd.name);
                $('#cf-form-submit-label').val(fd.settings.submit_label || 'Submit');
                $('#cf-form-success-msg').val(fd.settings.success_message || "Thank you! We'll be in touch soon.");
                (fd.fields || []).forEach(function (f) {
                    $('#cf-fields-container').append(buildFieldRow(f, allDests));
                });
                $('#cf-placements-section').show();
                cfLoadPlacements(formId);
            }
            $('#cf-forms-list-panel').hide();
            $('#cf-form-editor').show();
        });
    };

    window.cfHideFormEditor = function () {
        $('#cf-form-editor').hide();
        $('#cf-forms-list-panel').show();
    };

    // ── Save form ──────────────────────────────────────────────────────────

    window.cfSaveForm = function () {
        var name         = $('#cf-form-name').val().trim();
        var submitLabel  = $('#cf-form-submit-label').val().trim() || 'Submit';
        var successMsg   = $('#cf-form-success-msg').val().trim();

        if (!name) { alert_float('danger', 'Form name is required.'); return; }

        var fields       = [];
        var singleUsed   = {};
        var dupError     = null;

        $('#cf-fields-container .cf-field-row').each(function () {
            var row    = $(this);
            var type   = row.find('.cf-field-type').val();
            var destId = parseInt(row.find('.cf-field-ghl').val(), 10) || null;
            var dest   = destId && cachedGhlDests ? cachedGhlDests.find(function (d) { return d.id === destId; }) : null;
            var opts   = [];
            if (type === 'select' || type === 'multi_select') {
                var raw = row.find('.cf-field-options').val().trim();
                if (raw) {
                    opts = raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                }
            }
            // Only Single Input destinations enforce uniqueness
            if (dest && dest.mode === 'single') {
                if (singleUsed[dest.id]) { dupError = dest.label; return false; }
                singleUsed[dest.id] = true;
            }
            fields.push({
                label:         row.find('.cf-field-label').val(),
                type:          type,
                ghl_dest_id:   destId,
                ghl_field:     dest ? dest.ghl_key : '',
                ghl_dest_mode: dest ? dest.mode    : '',
                required:      row.find('.cf-field-required').prop('checked'),
                options:       opts,
            });
        });

        if (dupError) {
            alert_float('danger', '"' + dupError + '" is a Single Input destination — only one field per form may use it.');
            return;
        }

        var settings = { submit_label: submitLabel, success_message: successMsg };

        var data = {
            form_id:  editingFormId,
            name:     name,
            fields:   JSON.stringify(fields),
            settings: JSON.stringify(settings),
        };

        $('#cf-save-msg').text('Saving…');
        ajax(ADMIN_URL + '/form_save/' + SITE_ID, data, function (r) {
            if (r.success) {
                alert_float('success', r.message);
                // Update local cache and refresh list
                var fid = r.form_id;
                var existing = formsData.find(function (f) { return f.id === fid; });
                if (existing) {
                    existing.name     = name;
                    existing.fields   = fields;
                    existing.settings = settings;
                } else {
                    formsData.push({ id: fid, name: name, type: 'custom', fields: fields, settings: settings });
                }
                $('#cf-save-msg').text('Saved.');
                if (editingFormId === 0) {
                    editingFormId = fid;
                    $('#cf-editing-form-id').val(fid);
                    $('#cf-editor-title').text('Edit: ' + name);
                    $('#cf-placements-section').show();
                    cfLoadPlacements(fid);
                }
                cfRefreshFormList();
            } else {
                alert_float('danger', r.message || 'Save failed.');
                $('#cf-save-msg').text('');
            }
        });
    };

    // ── Delete form ────────────────────────────────────────────────────────

    window.cfDeleteForm = function (formId, formName) {
        if (!confirm('Delete form "' + formName + '"? This cannot be undone.')) { return; }
        ajax(ADMIN_URL + '/form_delete/' + formId, {}, function (r) {
            if (r.success) {
                formsData = formsData.filter(function (f) { return f.id !== formId; });
                cfRefreshFormList();
                alert_float('success', 'Form deleted.');
            } else {
                alert_float('danger', r.message || 'Delete failed.');
            }
        });
    };

    // ── Placements ─────────────────────────────────────────────────────────

    function cfLoadPlacements(formId) {
        var tbody = $('#cf-placements-body');
        tbody.html('<tr><td colspan="3" class="text-muted">Loading…</td></tr>');
        $.getJSON(ADMIN_URL + '/form_placements_json/' + formId, function (r) {
            tbody.empty();
            if (!r.success || !r.placements.length) {
                tbody.html('<tr><td colspan="3" class="text-muted">No placements yet.</td></tr>');
                return;
            }
            r.placements.forEach(function (p) {
                var row = $('<tr>');
                row.append($('<td>').text(p.page_title || 'Page #' + p.page_id));
                row.append($('<td>').text(p.placement));
                var removeBtn = $('<button class="btn btn-danger btn-xs">').html('<i class="fa fa-times"></i>')
                    .on('click', function () { cfRemovePlacement(p.id, row); });
                row.append($('<td>').append(removeBtn));
                tbody.append(row);
            });
        }).fail(function () {
            tbody.html('<tr><td colspan="3" class="text-muted">Could not load placements.</td></tr>');
        });
    }

    window.cfAddPlacement = function () {
        var pageId    = $('#cf-place-page').val();
        var placement = $('#cf-place-type').val();
        if (!pageId) { alert_float('danger', 'Select a page.'); return; }
        ajax(ADMIN_URL + '/form_placement_add/' + editingFormId, { page_id: pageId, placement: placement }, function (r) {
            if (r.success) { cfLoadPlacements(editingFormId); }
            else { alert_float('danger', r.message || 'Failed.'); }
        });
    };

    function cfRemovePlacement(placementId, row) {
        ajax(ADMIN_URL + '/form_placement_remove/' + placementId, {}, function (r) {
            if (r.success) { row.remove(); }
            else { alert_float('danger', r.message || 'Failed.'); }
        });
    }

    // ── Refresh form list in-place ──────────────────────────────────────────

    function cfRefreshFormList() {
        var tbody = $('#cf-forms-list-panel table tbody');
        if (!tbody.length) {
            window.location.hash = '#tab-forms';
            window.location.reload();
            return;
        }
        tbody.empty();
        formsData.forEach(function (fd) {
            var isSystem = (fd.type === 'system');
            var row = $('<tr>').attr('data-form-row', fd.id);
            var nameCell = $('<td>').append($('<strong>').text(fd.name)).append('<br>').append($('<small class="text-muted">').text('ID: ' + fd.id));
            var typeCell = $('<td>').append(isSystem ? $('<span class="label label-info">').text('System') : $('<span class="label label-default">').text('Custom'));
            var countCell = $('<td>').text((fd.fields || []).length);
            var previewBtn = $('<button class="btn btn-info btn-xs" title="Preview">').html('<i class="fa fa-eye"></i>')
                .on('click', (function (fid) { return function () { cfPreviewForm(fid); }; }(fd.id)));
            var editBtn = $('<button class="btn btn-default btn-xs">').html('<i class="fa fa-pencil"></i> Edit')
                .on('click', function () { cfShowFormEditor(fd.id); });
            var actCell = $('<td>').append(previewBtn).append(' ').append(editBtn);
            if (!isSystem) {
                var delBtn = $('<button class="btn btn-danger btn-xs">').html('<i class="fa fa-trash"></i>')
                    .on('click', function () { cfDeleteForm(fd.id, fd.name); });
                actCell.append(' ').append(delBtn);
            }
            row.append(nameCell, typeCell, countCell, actCell);
            tbody.append(row);
        });
    }

    // ── Form preview ──────────────────────────────────────────────────────

    // Inject form CSS once so previewed forms render correctly inside the admin page.
    (function () {
        if (document.getElementById('cf-forms-css')) { return; }
        var s = document.createElement('style');
        s.id  = 'cf-forms-css';
        s.textContent = [
            '.cf-form{font-family:inherit}',
            '.cf-field{margin-bottom:14px}',
            '.cf-field label{display:block;font-size:14px;font-weight:600;margin-bottom:4px}',
            '.cf-field input:not([type="checkbox"]),.cf-field select,.cf-field textarea{width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #ccc;border-radius:4px;font-size:15px;font-family:inherit}',
            '.cf-field select{appearance:auto}',
            '.cf-field textarea{resize:vertical}',
            '.cf-required{color:#e74c3c;margin-left:2px}',
            '.cf-submit{display:inline-block;padding:11px 28px;background:#2563eb;color:#fff;border:none;border-radius:4px;font-size:15px;font-weight:600;cursor:pointer}',
            '.cf-submit:hover{background:#1d4ed8}',
            '.cf-submit:disabled{opacity:.6;cursor:default}',
            '.cf-msg{margin-top:10px;font-size:14px;padding:9px 12px;border-radius:4px}',
            '.cf-msg.cf-success{background:#d1fae5;color:#065f46}',
            '.cf-msg.cf-error{background:#fee2e2;color:#991b1b}',
        ].join('\n');
        document.head.appendChild(s);
    }());

    window.cfPreviewForm = function (formId) {
        var fd = formsData.find(function (f) { return f.id === formId; });
        if (!fd) { return; }
        $('#cf-preview-title').text('Preview: ' + fd.name);
        var body = $('#cf-preview-body');
        body.html('<p class="text-muted" style="padding:20px 24px;">Loading…</p>');
        $('#cf-preview-modal').modal('show');
        $.getJSON(RUNTIME_URL + '/form_render/' + formId, function (r) {
            if (!r.success) { body.html('<p class="text-danger" style="padding:20px 24px;">Could not load form.</p>'); return; }
            body.html('<div style="padding:24px;">' + r.html + '</div>');
        }).fail(function () {
            body.html('<p class="text-danger" style="padding:20px 24px;">Could not load form.</p>');
        });
    };

    $('#cf-preview-modal').on('hidden.bs.modal', function () {
        $('#cf-preview-body').empty();
    });

    // Submit handler for previewed forms — mirrors runtime_js.php submitForm()
    $('#cf-preview-body').on('submit', '.cf-form', function (e) {
        e.preventDefault();
        var formEl    = this;
        var formId    = formEl.getAttribute('data-form-id');
        var inputs    = formEl.querySelectorAll('[name^="cf_field"]');
        var fields    = {};
        for (var i = 0; i < inputs.length; i++) {
            var inp  = inputs[i];
            var name = inp.name.replace(/^cf_field\[/, '').replace(/\]$/, '');
            fields[name] = (inp.type === 'checkbox') ? (inp.checked ? (inp.value || 'yes') : '') : inp.value;
        }
        var groups = formEl.querySelectorAll('[data-cf-idx]');
        for (var j = 0; j < groups.length; j++) {
            var grp  = groups[j];
            var idx  = grp.getAttribute('data-cf-idx');
            var chk  = grp.querySelectorAll('.cf-ms-opt:checked');
            var vals = [];
            for (var k = 0; k < chk.length; k++) { vals.push(chk[k].value); }
            fields[idx] = vals.join(', ');
        }
        var submitBtn = formEl.querySelector('.cf-submit');
        var msgEl     = formEl.querySelector('.cf-msg');
        if (submitBtn) { submitBtn.disabled = true; }
        if (msgEl)     { msgEl.style.display = 'none'; msgEl.className = 'cf-msg'; }
        $.ajax({
            url: RUNTIME_URL + '/form_submit', type: 'POST', contentType: 'application/json',
            data: JSON.stringify({ form_id: parseInt(formId, 10), site_token: '', fields: fields }),
            success: function (data) {
                if (submitBtn) { submitBtn.disabled = false; }
                if (msgEl) {
                    msgEl.style.display = 'block';
                    if (data.success) {
                        msgEl.className = 'cf-msg cf-success'; msgEl.textContent = data.message || 'Thank you!'; formEl.reset();
                    } else {
                        msgEl.className = 'cf-msg cf-error'; msgEl.textContent = data.error || 'Something went wrong.';
                    }
                }
            },
            error: function () {
                if (submitBtn) { submitBtn.disabled = false; }
                if (msgEl) { msgEl.className = 'cf-msg cf-error'; msgEl.textContent = 'Request failed.'; msgEl.style.display = 'block'; }
            }
        });
    });

    // ── Custom GHL Fields (per-site destination registry) ─────────────────

    window.cfShowCustomFields = function () {
        $('#cf-forms-list-panel').hide();
        $('#cf-custom-fields-panel').show();
        // Load destinations then render only site-specific rows
        loadGhlDestinations(function () { cfRenderCustomFields(); });
    };

    window.cfHideCustomFields = function () {
        $('#cf-custom-fields-panel').hide();
        $('#cf-forms-list-panel').show();
    };

    function cfRenderCustomFields() {
        var siteDests = (cachedGhlDests || []).filter(function (d) { return !d.global; });
        var tbody = $('#cf-custom-fields-body');
        tbody.empty();
        if (!siteDests.length) {
            tbody.html('<tr><td colspan="4" class="text-muted">No custom fields yet. Add one below.</td></tr>');
            return;
        }
        siteDests.forEach(function (d) {
            tbody.append(cfBuildCustomFieldRow(d));
        });
    }

    function cfBuildCustomFieldRow(d) {
        var row = $('<tr>').attr('data-dest-id', d.id);
        var labelCell = $('<td class="cf-cfd-label-cell">').text(d.label);
        var keyCell   = $('<td class="cf-cfd-key-cell">').text(d.ghl_key || '—');
        var modeCell  = $('<td class="cf-cfd-mode-cell">').text(d.mode === 'multiple' ? 'Multiple Inputs' : 'Single Input');
        var editBtn   = $('<button class="btn btn-default btn-xs">').html('<i class="fa fa-pencil"></i> Edit')
            .on('click', function () { cfEditCustomFieldRow(row, d); });
        var delBtn    = $('<button class="btn btn-danger btn-xs" style="margin-left:4px;">').html('<i class="fa fa-trash"></i>')
            .on('click', function () { cfDeleteCustomField(d.id, row); });
        var actCell   = $('<td>').append(editBtn).append(delBtn);
        return row.append(labelCell, keyCell, modeCell, actCell);
    }

    function cfEditCustomFieldRow(row, d) {
        var labelInput = $('<input type="text" class="form-control input-sm cf-cfd-edit-label">').val(d.label);
        var keyInput   = $('<input type="text" class="form-control input-sm cf-cfd-edit-key">').val(d.ghl_key || '');
        var modeSelect = $('<select class="form-control input-sm cf-cfd-edit-mode">')
            .append($('<option>').val('single').text('Single Input').prop('selected', d.mode !== 'multiple'))
            .append($('<option>').val('multiple').text('Multiple Inputs').prop('selected', d.mode === 'multiple'));
        var saveBtn   = $('<button class="btn btn-primary btn-xs">').html('<i class="fa fa-check"></i> Save')
            .on('click', function () { cfSaveCustomFieldRow(row, d.id); });
        var cancelBtn = $('<button class="btn btn-default btn-xs" style="margin-left:4px;">').text('Cancel')
            .on('click', function () { cfRenderCustomFields(); });
        row.find('.cf-cfd-label-cell').empty().append(labelInput);
        row.find('.cf-cfd-key-cell').empty().append(keyInput);
        row.find('.cf-cfd-mode-cell').empty().append(modeSelect);
        row.find('td:last').empty().append(saveBtn).append(cancelBtn);
    }

    function cfSaveCustomFieldRow(row, id) {
        var label = row.find('.cf-cfd-edit-label').val().trim();
        var key   = row.find('.cf-cfd-edit-key').val().trim();
        var mode  = row.find('.cf-cfd-edit-mode').val();
        if (!label) { alert_float('danger', 'Display name is required.'); return; }
        ajax(ADMIN_URL + '/ghl_dest_save', { id: id, label: label, ghl_key: key, mode: mode }, function (r) {
            if (r.success) {
                CSRF_HASH = r.csrf_hash;
                cachedGhlDests = null;
                loadGhlDestinations(function () { cfRenderCustomFields(); });
                alert_float('success', 'Destination updated.');
            } else {
                alert_float('danger', r.message || 'Save failed.');
            }
        });
    }

    window.cfAddCustomField = function () {
        var label = $('#cf-new-cfd-label').val().trim();
        var key   = $('#cf-new-cfd-key').val().trim();
        var mode  = $('#cf-new-cfd-mode').val();
        if (!label) { alert_float('danger', 'Display name is required.'); return; }
        ajax(ADMIN_URL + '/ghl_dest_save', { id: 0, label: label, ghl_key: key, mode: mode, site_id: SITE_ID }, function (r) {
            if (r.success) {
                CSRF_HASH = r.csrf_hash;
                $('#cf-new-cfd-label').val('');
                $('#cf-new-cfd-key').val('');
                $('#cf-new-cfd-mode').val('single');
                cachedGhlDests = null;
                loadGhlDestinations(function () { cfRenderCustomFields(); });
                alert_float('success', 'Custom field added.');
            } else {
                alert_float('danger', r.message || 'Add failed.');
            }
        });
    };

    function cfDeleteCustomField(id, row) {
        if (!confirm('Remove this custom GHL field? Form fields mapped to it will lose their destination.')) { return; }
        ajax(ADMIN_URL + '/ghl_dest_delete/' + id, {}, function (r) {
            if (r.success) {
                CSRF_HASH = r.csrf_hash;
                row.remove();
                cachedGhlDests = null;
                if (!$('#cf-custom-fields-body tr[data-dest-id]').length) {
                    $('#cf-custom-fields-body').html('<tr><td colspan="4" class="text-muted">No custom fields yet. Add one below.</td></tr>');
                }
                alert_float('success', 'Custom field removed.');
            } else {
                alert_float('danger', r.message || 'Delete failed.');
            }
        });
    }

}());
</script>
