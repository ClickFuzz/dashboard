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
                            <button class="btn btn-primary btn-sm" onclick="cfShowFormEditor(0)">
                                <i class="fa fa-plus"></i> New Form
                            </button>
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
                                    <th width="100">Actions</th>
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

                <?php } ?>
            </div><!-- /#tab-forms -->

<script>
(function () {
    var SITE_ID     = <?php echo (int) ($site->id ?? 0); ?>;
    var ADMIN_URL   = '<?php echo addslashes(admin_url("pitchsnap")); ?>';
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

    var editingFormId = 0;

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

    // ── Field row builder ──────────────────────────────────────────────────

    var GHL_FIELDS = ['full_name','first_name','last_name','email','phone','message','service_type','address','city','state','zip'];

    function buildFieldRow(field) {
        field = field || {};
        var row = $('<div class="cf-field-row" style="display:flex;gap:6px;align-items:flex-start;margin-bottom:6px;">');

        var labelInput = $('<input type="text" class="form-control input-sm cf-field-label" placeholder="Label">').val(field.label || '');

        var typeSelect = $('<select class="form-control input-sm cf-field-type">');
        ['text','tel','email','textarea'].forEach(function (t) {
            typeSelect.append($('<option>').val(t).text(t).prop('selected', t === (field.type || 'text')));
        });

        var ghlSelect = $('<select class="form-control input-sm cf-field-ghl">');
        ghlSelect.append($('<option>').val('').text('— GHL field —'));
        GHL_FIELDS.forEach(function (g) {
            ghlSelect.append($('<option>').val(g).text(g).prop('selected', g === (field.ghl_field || '')));
        });

        var reqCheck = $('<label style="white-space:nowrap;margin:0;padding-top:6px;">')
            .append($('<input type="checkbox" class="cf-field-required">').prop('checked', !!field.required))
            .append(' Req');

        var removeBtn = $('<button type="button" class="btn btn-danger btn-xs" style="margin-top:2px;">').html('<i class="fa fa-times"></i>')
            .on('click', function () { row.remove(); });

        row.append(labelInput, typeSelect, ghlSelect, reqCheck, removeBtn);
        return row;
    }

    window.cfAddField = function (field) {
        $('#cf-fields-container').append(buildFieldRow(field));
    };

    // ── Editor open/close ──────────────────────────────────────────────────

    window.cfShowFormEditor = function (formId) {
        editingFormId = formId;
        $('#cf-editing-form-id').val(formId);
        $('#cf-fields-container').empty();
        $('#cf-save-msg').text('');
        $('#cf-placements-section').hide();

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
            (fd.fields || []).forEach(function (f) { cfAddField(f); });
            $('#cf-placements-section').show();
            cfLoadPlacements(formId);
        }

        $('#cf-forms-list-panel').hide();
        $('#cf-form-editor').show();
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

        var fields = [];
        $('#cf-fields-container .cf-field-row').each(function () {
            var row = $(this);
            fields.push({
                label:     row.find('.cf-field-label').val(),
                type:      row.find('.cf-field-type').val(),
                ghl_field: row.find('.cf-field-ghl').val(),
                required:  row.find('.cf-field-required').prop('checked'),
            });
        });

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
        // Simple approach: rebuild the table body from formsData
        var tbody = $('#cf-forms-list-panel table tbody');
        if (!tbody.length) {
            // No table exists (was empty state) — reload page to show table
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
            var editBtn = $('<button class="btn btn-default btn-xs">').html('<i class="fa fa-pencil"></i> Edit')
                .on('click', function () { cfShowFormEditor(fd.id); });
            var actCell = $('<td>').append(editBtn);
            if (!isSystem) {
                var delBtn = $('<button class="btn btn-danger btn-xs">').html('<i class="fa fa-trash"></i>')
                    .on('click', function () { cfDeleteForm(fd.id, fd.name); });
                actCell.append(' ').append(delBtn);
            }
            row.append(nameCell, typeCell, countCell, actCell);
            tbody.append(row);
        });
    }

}());
</script>
