<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
// Build conditions list for JS from all questions across all sections
$_all_conditions = [];
$_section_count  = count($sections);
if (!isset($_existing_data)) $_existing_data = [];
foreach ($sections as $_si => $_sec) {
    foreach ($_sec['questions'] as $_q) {
        if (!empty($_q['condition_question_id'])) {
            $_all_conditions[] = [
                'q_id'    => (int) $_q['id'],
                'ctrl_id' => (int) $_q['condition_question_id'],
                'op'      => $_q['condition_operator'],
                'val'     => $_q['condition_value'],
            ];
        }
    }
}
function _wiz_e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function _wiz_field($q, $existing_val = null) {
    $id   = 'q_' . $q['id'];
    $name = 'q_' . $q['id'];
    $req  = $q['required'] ? ' required' : '';
    $ft   = $q['field_type'];
    $cls  = 'wiz-input';

    if ($ft === 'textarea') {
        return '<textarea id="' . $id . '" name="' . $name . '" class="' . $cls . '" rows="4"' . $req . '>'
            . _wiz_e($existing_val ?? '')
            . '</textarea>';
    }
    if ($ft === 'select') {
        $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
        $html = '<select id="' . $id . '" name="' . $name . '" class="' . $cls . '"' . $req . '><option value="">— select —</option>';
        foreach ($opts as $o) {
            $sel = ($existing_val !== null && $existing_val === $o) ? ' selected' : '';
            $html .= '<option value="' . _wiz_e($o) . '"' . $sel . '>' . _wiz_e($o) . '</option>';
        }
        return $html . '</select>';
    }
    if ($ft === 'radio') {
        $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
        $html = '<div class="wiz-radio-group">';
        foreach ($opts as $o) {
            $chk = ($existing_val !== null && $existing_val === $o) ? ' checked' : '';
            $html .= '<label class="wiz-radio-label"><input type="radio" name="' . $name . '" value="' . _wiz_e($o) . '"' . $chk . $req . '> ' . _wiz_e($o) . '</label>';
        }
        return $html . '</div>';
    }
    if ($ft === 'checkbox') {
        $opts    = json_decode($q['options_json'] ?? '[]', true) ?: [];
        $checked = ($existing_val !== null) ? (json_decode($existing_val, true) ?: []) : [];
        $checked = array_map('strval', is_array($checked) ? $checked : []);
        $html = '<div class="wiz-checkbox-group">';
        foreach ($opts as $o) {
            $chk = in_array((string) $o, $checked, true) ? ' checked' : '';
            $html .= '<label class="wiz-checkbox-label"><input type="checkbox" name="' . $name . '[]" value="' . _wiz_e($o) . '"' . $chk . '> ' . _wiz_e($o) . '</label>';
        }
        return $html . '</div>';
    }
    if ($ft === 'yes_no') {
        $chk_yes = ($existing_val === 'yes') ? ' checked' : '';
        $chk_no  = ($existing_val === 'no')  ? ' checked' : '';
        return '<div class="wiz-radio-group">'
            . '<label class="wiz-radio-label"><input type="radio" name="' . $name . '" value="yes"' . $chk_yes . $req . '> Yes</label>'
            . '<label class="wiz-radio-label"><input type="radio" name="' . $name . '" value="no"'  . $chk_no  . $req . '> No</label>'
            . '</div>';
    }
    if ($ft === 'question_builder') {
        $prefill_attr = ($existing_val !== null && $existing_val !== '')
            ? ' data-prefill="' . htmlspecialchars($existing_val, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        return '<div class="wiz-qb" data-name="' . $name . '"' . $prefill_attr . '></div>'
            . '<input type="hidden" id="' . $id . '" name="' . $name . '" class="wiz-qb-value">';
    }
    if ($ft === 'file') {
        $qid      = (int) $q['id'];
        $uploaded = null;
        if ($existing_val !== null && $existing_val !== '') {
            $ref = json_decode($existing_val, true);
            if (is_array($ref) && ($ref['_type'] ?? '') === 'ob_file') {
                $uploaded = $ref;
            }
        }
        $accept = 'accept=".pdf,.jpg,.jpeg,.png"';
        if ($uploaded) {
            $disp = _wiz_e($uploaded['original_name'] ?? $uploaded['filename'] ?? '');
            return '<div class="wiz-file-wrap" data-qid="' . $qid . '">'
                . '<div class="wiz-file-current">Uploaded: <strong>' . $disp . '</strong></div>'
                . '<input type="hidden" name="q_' . $qid . '" value="__ob_file__" class="wiz-file-sentinel">'
                . '<label class="wiz-file-replace-label" style="display:block;margin-top:8px;font-size:13px;cursor:pointer;color:rgba(255,255,255,.6);">Replace: <input type="file" class="wiz-file-input" data-qid="' . $qid . '" ' . $accept . '></label>'
                . '</div>';
        }
        return '<div class="wiz-file-wrap" data-qid="' . $qid . '">'
            . '<input type="file" name="q_' . $qid . '" class="wiz-file-input wiz-input" data-qid="' . $qid . '" ' . $accept . $req . '>'
            . '<small class="wiz-file-hint" style="display:block;margin-top:6px;color:rgba(255,255,255,.5);font-size:12px;">Accepted: PDF, JPG, PNG &mdash; max 10 MB</small>'
            . '</div>';
    }
    if ($ft === 'phone_number_picker') {
        $qid     = (int) $q['id'];
        $has_val = ($existing_val !== null && $existing_val !== '');
        $friendly = '';
        if ($has_val && preg_match('/^\+1([0-9]{10})$/', $existing_val, $_m)) {
            $_d = $_m[1];
            $friendly = '(' . substr($_d, 0, 3) . ') ' . substr($_d, 3, 3) . '-' . substr($_d, 6);
        }
        $hidden_val = $has_val ? ' value="' . _wiz_e($existing_val) . '"' : '';
        $html = '<div class="wiz-phone-picker" data-qid="' . $qid . '">';
        if ($has_val) {
            $html .= '<div class="wiz-phone-selected">Selected: <strong>' . _wiz_e($friendly ?: $existing_val) . '</strong>'
                   . ' <button type="button" class="wiz-phone-change-btn" onclick="wizPhonePickerReset(' . $qid . ')">Change</button></div>';
            $html .= '<div class="wiz-phone-search-wrap" style="display:none;">';
        } else {
            $html .= '<div class="wiz-phone-search-wrap">';
        }
        $html .= '<input type="text" class="wiz-input wiz-phone-search" placeholder="Enter area code or digits (e.g. 512)" maxlength="10" autocomplete="off" oninput="wizPhoneSearch(' . $qid . ', this.value)">'
               . '<div class="wiz-phone-results" id="wiz-phone-results-' . $qid . '"></div>'
               . '</div>';
        $html .= '<input type="hidden" id="q_' . $qid . '" name="q_' . $qid . '" class="wiz-phone-value"' . $hidden_val . $req . '>';
        $html .= '<p class="wiz-help" style="margin-top:6px;">Search available US local numbers by area code. The selected number will be provisioned for your account.</p>';
        $html .= '</div>';
        return $html;
    }
    $type_map = ['text'=>'text','number'=>'number','email'=>'email','phone'=>'tel','url'=>'url'];
    $type = $type_map[$ft] ?? 'text';
    $val_attr = ($existing_val !== null && $existing_val !== '') ? ' value="' . _wiz_e($existing_val) . '"' : '';
    return '<input type="' . $type . '" id="' . $id . '" name="' . $name . '" class="' . $cls . '"' . $req . $val_attr . '>';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo _wiz_e($flow['name']); ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { background: transparent; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 15px; line-height: 1.6;
            color: #fff;
            padding: 10px 16px 56px;
        }
        .wiz-page { max-width: 620px; margin: 0 auto; }

        /* Step dots */
        .wiz-header { padding: 0 0 24px; }
        .wiz-header h1 { display: none; }
        .wiz-header p  { display: none; }
        .wiz-dots { display: flex; justify-content: center; align-items: center; gap: 10px; }
        .wiz-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: rgba(255,255,255,.18);
            transition: background .25s, transform .2s;
        }
        .wiz-dot.wiz-dot-done    { background: #e0392b; }
        .wiz-dot.wiz-dot-current { background: #e0392b; transform: scale(1.25); }

        /* Body — fully transparent, no card */
        .wiz-body { background: transparent; padding: 0; box-shadow: none; border-radius: 0; }

        .wiz-step { display: none; }
        .wiz-step.active { display: block; }
        .wiz-step-title {
            font-size: 18px; font-weight: 700; color: #fff;
            margin-bottom: 4px; letter-spacing: -.2px;
        }
        .wiz-step-desc { color: rgba(255,255,255,.55); font-size: 13px; margin-bottom: 24px; }

        .wiz-question { margin-bottom: 20px; }
        .wiz-question.wiz-hidden { display: none; }
        .wiz-label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; color: rgba(255,255,255,.9); }
        .wiz-required { color: #e0392b; margin-left: 2px; }
        .wiz-help { font-size: 12px; color: rgba(255,255,255,.4); margin-top: 4px; }

        /* Inputs */
        .wiz-input {
            width: 100%; padding: 11px 14px;
            background: rgba(255,255,255,.07);
            border: 1.5px solid rgba(255,255,255,.18);
            border-radius: 8px;
            font-size: 15px; color: #fff;
            transition: border-color .2s;
        }
        .wiz-input::placeholder { color: rgba(255,255,255,.28); }
        .wiz-input:focus { outline: none; border-color: #e0392b; background: rgba(255,255,255,.1); }
        select.wiz-input option { background: #1a1a1a; color: #fff; }

        /* Radio / checkbox */
        .wiz-radio-group, .wiz-checkbox-group { display: flex; flex-direction: column; gap: 10px; margin-top: 2px; }
        .wiz-radio-label, .wiz-checkbox-label {
            display: flex; align-items: center; gap: 10px;
            cursor: pointer; font-size: 14px; font-weight: normal;
            color: rgba(255,255,255,.85);
            background: rgba(255,255,255,.06);
            border: 1.5px solid rgba(255,255,255,.14);
            border-radius: 8px; padding: 10px 14px;
            transition: border-color .15s, background .15s;
        }
        .wiz-radio-label:hover, .wiz-checkbox-label:hover {
            border-color: rgba(255,255,255,.3); background: rgba(255,255,255,.1);
        }
        .wiz-radio-label input, .wiz-checkbox-label input { width: 16px; height: 16px; flex-shrink: 0; accent-color: #e0392b; }

        /* Nav */
        .wiz-nav {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 32px; padding-top: 24px;
            border-top: 1px solid rgba(255,255,255,.1);
        }
        .wiz-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 11px 28px; border-radius: 8px; font-size: 14px;
            font-weight: 700; cursor: pointer;
            transition: opacity .15s, transform .1s;
            letter-spacing: .2px;
        }
        .wiz-btn:hover { opacity: .88; transform: translateY(-1px); }
        .wiz-btn-primary { background: #e0392b; color: #fff; border: none; }
        .wiz-btn-secondary {
            background: transparent; color: rgba(255,255,255,.7);
            border: 1.5px solid rgba(255,255,255,.22);
        }
        .wiz-btn:disabled { opacity: .35; cursor: not-allowed; transform: none; }
        .wiz-step-counter { font-size: 13px; color: rgba(255,255,255,.38); }
        .wiz-submit-note { font-size: 12px; color: rgba(255,255,255,.3); margin-top: 8px; text-align: center; }

        /* Question Builder */
        .wiz-qb { display: flex; flex-direction: column; gap: 12px; }
        .wiz-qb-row {
            border: 1.5px solid rgba(255,255,255,.14); border-radius: 8px;
            padding: 14px 14px 10px; background: rgba(255,255,255,.06);
            display: flex; flex-direction: column; gap: 8px; position: relative;
        }
        .wiz-qb-row-header { display: flex; align-items: center; gap: 8px; }
        .wiz-qb-handle { cursor: grab; color: rgba(255,255,255,.35); font-size: 16px; line-height: 1; flex-shrink: 0; }
        .wiz-qb-num { font-size: 12px; color: rgba(255,255,255,.35); flex-shrink: 0; }
        .wiz-qb-rm {
            margin-left: auto; background: none; border: none;
            color: #e0392b; cursor: pointer; font-size: 18px; line-height: 1; padding: 0 2px;
        }
        .wiz-qb-fields { display: flex; gap: 8px; flex-wrap: wrap; }
        .wiz-qb-label-wrap { flex: 1 1 200px; }
        .wiz-qb-type-wrap  { flex: 0 0 160px; }
        .wiz-qb-fields label { display: block; font-size: 12px; font-weight: 600; color: rgba(255,255,255,.55); margin-bottom: 3px; }
        .wiz-qb-opts { margin-top: 4px; display: flex; flex-direction: column; gap: 6px; }
        .wiz-qb-opt-row { display: flex; gap: 6px; align-items: center; }
        .wiz-qb-opt-row input { flex: 1; }
        .wiz-qb-opt-rm { background: none; border: none; color: #e0392b; cursor: pointer; font-size: 16px; line-height: 1; padding: 0; }
        .wiz-qb-add-opt {
            font-size: 12px; color: rgba(255,255,255,.6); background: none;
            border: 1px solid rgba(255,255,255,.2); border-radius: 6px;
            padding: 3px 10px; cursor: pointer; margin-top: 2px;
        }
        .wiz-qb-add-row {
            display: flex; align-items: center; justify-content: center;
            border: 1.5px dashed rgba(255,255,255,.2); border-radius: 8px; padding: 10px;
            color: rgba(255,255,255,.5); font-size: 13px; font-weight: 600;
            cursor: pointer; background: none; width: 100%;
        }
        .wiz-qb-add-row:hover { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.35); }

        @media (max-width: 500px) {
            .wiz-header { padding: 20px; }
            .wiz-body  { padding: 20px; }
        }

        /* Phone Number Picker */
        .wiz-phone-picker { position: relative; }
        .wiz-phone-selected { font-size: 14px; color: rgba(255,255,255,.85); margin-bottom: 6px; }
        .wiz-phone-change-btn {
            background: none; border: 1px solid rgba(255,255,255,.3); border-radius: 4px;
            color: rgba(255,255,255,.6); font-size: 12px; padding: 2px 8px;
            cursor: pointer; margin-left: 8px;
        }
        .wiz-phone-change-btn:hover { border-color: rgba(255,255,255,.6); color: #fff; }
        .wiz-phone-results { margin-top: 8px; display: flex; flex-direction: column; gap: 6px; }
        .wiz-phone-result-item {
            padding: 10px 14px; border-radius: 8px; font-size: 14px; font-weight: 600;
            background: rgba(255,255,255,.08); border: 1.5px solid rgba(255,255,255,.14);
            color: rgba(255,255,255,.9); cursor: pointer; transition: background .15s, border-color .15s;
        }
        .wiz-phone-result-item:hover { background: rgba(255,255,255,.15); border-color: rgba(255,255,255,.35); }
        .wiz-phone-result-selected { background: rgba(224,57,43,.25) !important; border-color: #e0392b !important; }
        .wiz-phone-search-msg { font-size: 13px; color: rgba(255,255,255,.45); padding: 8px 0; }
    </style>
</head>
<body>
<div class="wiz-page">
    <div class="wiz-header">
        <h1><?php echo _wiz_e($flow['name']); ?></h1>
        <?php if ($_section_count > 1) { ?>
        <div class="wiz-dots" id="wiz-dots">
            <?php for ($i = 0; $i < $_section_count; $i++) { ?>
            <span class="wiz-dot<?php echo $i === 0 ? ' wiz-dot-current' : ''; ?>"></span>
            <?php } ?>
        </div>
        <?php } ?>
    </div>
    <div class="wiz-body">
        <?php foreach ($sections as $_si => $_sec) { ?>
        <div class="wiz-step<?php echo $_si === 0 ? ' active' : ''; ?>" data-step="<?php echo $_si; ?>">
            <div class="wiz-step-title"><?php echo _wiz_e($_sec['name']); ?></div>
            <?php if (!empty($_sec['description'])) { ?><div class="wiz-step-desc"><?php echo _wiz_e($_sec['description']); ?></div><?php } ?>

            <?php foreach ($_sec['questions'] as $_q) { ?>
            <div class="wiz-question<?php echo !empty($_q['condition_question_id']) ? ' wiz-hidden' : ''; ?>"
                 id="wiz-q-<?php echo (int) $_q['id']; ?>"
                 data-q-id="<?php echo (int) $_q['id']; ?>"
                 <?php if (!empty($_q['data_key'])) { ?>data-data-key="<?php echo _wiz_e(trim($_q['data_key'])); ?>"<?php } ?>
                 <?php if (!empty($_q['condition_question_id'])) { ?>
                 data-ctrl-id="<?php echo (int) $_q['condition_question_id']; ?>"
                 data-ctrl-op="<?php echo _wiz_e($_q['condition_operator']); ?>"
                 data-ctrl-val="<?php echo _wiz_e($_q['condition_value']); ?>"
                 <?php } ?>>
                <label class="wiz-label" for="q_<?php echo (int) $_q['id']; ?>">
                    <?php echo _wiz_e($_q['label']); ?>
                    <?php if ($_q['required']) { ?><span class="wiz-required">*</span><?php } ?>
                </label>
                <?php echo _wiz_field($_q, $_existing_data[trim($_q['data_key'] ?? '')] ?? null); ?>
                <?php if (!empty($_q['help_text'])) { ?><p class="wiz-help"><?php echo _wiz_e($_q['help_text']); ?></p><?php } ?>
            </div>
            <?php } ?>

            <div class="wiz-nav">
                <?php if ($_si === 0) { ?>
                <span></span>
                <?php } else { ?>
                <button type="button" class="wiz-btn wiz-btn-secondary" onclick="wizGo(<?php echo $_si - 1; ?>)">&#8592; Previous</button>
                <?php } ?>
                <span class="wiz-step-counter"><?php echo $_si + 1; ?> / <?php echo $_section_count; ?></span>
                <?php if ($_si < $_section_count - 1) { ?>
                <button type="button" class="wiz-btn wiz-btn-primary" onclick="wizGo(<?php echo $_si + 1; ?>)">Next &#8594;</button>
                <?php } else { ?>
                <button type="button" id="wiz-submit-btn" class="wiz-btn wiz-btn-primary" onclick="wizSubmit()">Submit &#10003;</button>
                <?php } ?>
            </div>
            <?php if ($_si === $_section_count - 1) { ?>
            <p id="wiz-submit-error" style="display:none;color:#ff6b6b;text-align:center;margin-top:12px;font-size:14px;"></p>
            <?php } ?>
        </div>
        <?php } ?>
        <?php if ($_section_count === 0) { ?>
        <p style="color:#888;text-align:center;padding:32px 0;">This onboarding flow has no questions yet.</p>
        <?php } ?>
        <?php if ($_section_count > 1) { ?>
        <div class="wiz-submit-note" id="wiz-submit-note" style="display:none;">We&rsquo;ll process your responses once you submit.</div>
        <?php } ?>
    </div>
</div>

<script>
var _wizTotal      = <?php echo $_section_count; ?>;
var _wizConditions = <?php echo json_encode($_all_conditions); ?>;
var _wizToken      = '<?php echo addslashes($link['token'] ?? ''); ?>';
var _wizSubmitUrl      = '<?php echo addslashes(base_url('pitchsnap/onboarding_submit')); ?>';
var _wizSaveUrl        = '<?php echo addslashes(base_url('pitchsnap/onboarding_save_progress')); ?>';
var _wizUploadFileUrl  = '<?php echo addslashes(base_url('pitchsnap/onboarding_file_upload')); ?>';
var _wizPhoneSearchUrl = '<?php echo addslashes(base_url('pitchsnap/onboarding_phone_search')); ?>';

function wizGetVal(qId) {
    var inputs = document.querySelectorAll('[name="q_' + qId + '"]');
    if (!inputs.length) return '';
    if (inputs[0].type === 'radio') {
        var checked = document.querySelector('[name="q_' + qId + '"]:checked');
        return checked ? checked.value : '';
    }
    if (inputs[0].type === 'checkbox') {
        return Array.from(document.querySelectorAll('[name="q_' + qId + '[]"]:checked')).map(function(i) { return i.value; }).join(',');
    }
    return inputs[0].value;
}

function wizEvalConditions() {
    _wizConditions.forEach(function(c) {
        var wrapper = document.getElementById('wiz-q-' + c.q_id);
        if (!wrapper) return;
        var ctrlVal = wizGetVal(c.ctrl_id);
        var show = false;
        if (c.op === 'equals')     show = ctrlVal === c.val;
        if (c.op === 'not_equals') show = ctrlVal !== c.val;
        if (c.op === 'contains')   show = ctrlVal.indexOf(c.val) !== -1;
        wrapper.classList.toggle('wiz-hidden', !show);
    });
    setTimeout(_wizReportHeight, 50);
}

function wizGo(idx) {
    var activeStep = document.querySelector('.wiz-step.active');
    var activeIdx  = activeStep ? parseInt(activeStep.dataset.step, 10) : 0;
    if (idx > activeIdx && activeStep) {
        var blocked = false;
        activeStep.querySelectorAll('.wiz-question:not(.wiz-hidden)').forEach(function(wrap) {
            if (blocked) return;
            if (!wrap.querySelector('[required]')) return;
            var val = wizGetVal(parseInt(wrap.dataset.qId, 10));
            if (val === '' || val === null || val === undefined) {
                blocked = true;
                var lbl = wrap.querySelector('.wiz-label');
                var txt = lbl ? lbl.textContent.trim().replace(/\s*\*\s*$/, '') : 'required field';
                alert('Please answer: ' + txt);
            }
        });
        if (blocked) return;
        wizSaveStep(activeStep, function(ok) {
            if (ok) _wizGoNow(idx);
        });
        return;
    }
    _wizGoNow(idx);
}

function _wizGoNow(idx) {
    if (idx < 0 || idx >= _wizTotal) return;
    document.querySelectorAll('.wiz-step').forEach(function(el) { el.classList.remove('active'); });
    document.querySelectorAll('.wiz-step[data-step="' + idx + '"]').forEach(function(el) { el.classList.add('active'); });
    document.querySelectorAll('#wiz-dots .wiz-dot').forEach(function(dot, i) {
        dot.classList.toggle('wiz-dot-done',    i < idx);
        dot.classList.toggle('wiz-dot-current', i === idx);
    });
    var note = document.getElementById('wiz-submit-note');
    if (note) note.style.display = (idx === _wizTotal - 1) ? '' : 'none';
    wizEvalConditions();
    window.scrollTo({top: 0, behavior: 'smooth'});
    _wizReportHeight();
}

function wizUploadPendingFiles(scopeEl, callback) {
    var scope = scopeEl || document;
    var fileInputs = Array.from(scope.querySelectorAll('.wiz-file-input')).filter(function(el) {
        return el.files && el.files.length > 0;
    });
    if (!fileInputs.length) { callback(true); return; }
    var idx = 0;
    function uploadNext() {
        if (idx >= fileInputs.length) { callback(true); return; }
        var inp = fileInputs[idx++];
        var qId = inp.dataset.qid;
        var fd  = new FormData();
        fd.append('token', _wizToken);
        fd.append('question_id', qId);
        fd.append('ob_file', inp.files[0]);
        fetch(_wizUploadFileUrl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    var orig = inp.files[0].name;
                    var wrap = inp.closest('.wiz-file-wrap');
                    if (wrap) {
                        wrap.innerHTML = '<div class="wiz-file-current">Uploaded: <strong>' + orig + '</strong></div>'
                            + '<input type="hidden" name="q_' + qId + '" value="__ob_file__" class="wiz-file-sentinel">'
                            + '<label class="wiz-file-replace-label" style="display:block;margin-top:8px;font-size:13px;cursor:pointer;color:rgba(255,255,255,.6);">Replace: <input type="file" class="wiz-file-input" data-qid="' + qId + '" accept=".pdf,.jpg,.jpeg,.png"></label>';
                    }
                    if (d.extraction && d.extraction.success && d.extraction.populated && Object.keys(d.extraction.populated).length) {
                        _wizApplyExtracted(d.extraction.populated);
                    }
                    uploadNext();
                } else {
                    alert(d.error || 'File upload failed. Please try again.');
                    callback(false);
                }
            })
            .catch(function() {
                alert('File upload failed. Please check your connection and try again.');
                callback(false);
            });
    }
    uploadNext();
}

function _wizApplyExtracted(populated) {
    // populated = {data_key: value, ...} — write into any visible empty form fields
    Object.keys(populated).forEach(function(dk) {
        var val = populated[dk];
        if (!val) { return; }
        // Find a question element by its data-data-key attribute (set on .wiz-question wrappers)
        var qWrap = document.querySelector('.wiz-question[data-data-key="' + dk + '"]');
        if (!qWrap) { return; }
        var inp = qWrap.querySelector('input[type="text"], input[type="email"], input[type="number"], input[type="url"], input[type="tel"], textarea');
        if (inp && !inp.value) { inp.value = val; }
    });
    // Show a non-blocking notice
    var notice = document.getElementById('wiz-extract-notice');
    if (!notice) {
        notice = document.createElement('div');
        notice.id = 'wiz-extract-notice';
        notice.style.cssText = 'background:rgba(255,255,255,.12);border-radius:6px;padding:10px 14px;margin:12px 0;font-size:13px;color:rgba(255,255,255,.85);';
        notice.textContent = 'We found some business information in your document. Please review the pre-filled information as you continue.';
        var activeStep = document.querySelector('.wiz-step.wiz-active');
        if (activeStep) { activeStep.insertBefore(notice, activeStep.firstChild); }
    }
}

// ── Phone Number Picker ──────────────────────────────────────────────────────
var _wizPhoneTimers = {};

function wizPhoneSearch(qid, raw) {
    clearTimeout(_wizPhoneTimers[qid]);
    var search = raw.replace(/[^0-9]/g, '');
    var resultsEl = document.getElementById('wiz-phone-results-' + qid);
    if (!resultsEl) { return; }
    if (search.length < 3) {
        resultsEl.innerHTML = search.length ? '<p class="wiz-phone-search-msg">Enter at least 3 digits.</p>' : '';
        return;
    }
    resultsEl.innerHTML = '<p class="wiz-phone-search-msg">Searching&hellip;</p>';
    _wizPhoneTimers[qid] = setTimeout(function() {
        fetch(_wizPhoneSearchUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({token: _wizToken, search: search})
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) {
                if (d.unavailable) {
                    resultsEl.innerHTML = '<p class="wiz-phone-search-msg">' + (d.error || 'Search unavailable.') + '</p>';
                } else {
                    resultsEl.innerHTML = '<p class="wiz-phone-search-msg">' + (d.error || 'Search failed. Please try again.') + '</p>';
                }
                return;
            }
            if (!d.numbers || !d.numbers.length) {
                resultsEl.innerHTML = '<p class="wiz-phone-search-msg">No numbers found. Try a different area code.</p>';
                return;
            }
            var currentHidden = document.getElementById('q_' + qid);
            var currentVal = currentHidden ? currentHidden.value : '';
            var html = '';
            d.numbers.forEach(function(num) {
                var sel = (currentVal === num.e164) ? ' wiz-phone-result-selected' : '';
                html += '<div class="wiz-phone-result-item' + sel + '" onclick="wizPhonePickerSelect(' + qid + ', \'' + num.e164 + '\', \'' + num.friendly + '\')">' + num.friendly + '</div>';
            });
            resultsEl.innerHTML = html;
        }).catch(function() {
            resultsEl.innerHTML = '<p class="wiz-phone-search-msg">Search failed. Please check your connection.</p>';
        });
    }, 300);
}

function wizPhonePickerSelect(qid, e164, friendly) {
    var hidden = document.getElementById('q_' + qid);
    if (hidden) { hidden.value = e164; }
    var picker = hidden ? hidden.closest('.wiz-phone-picker') : null;
    if (!picker) { return; }
    var sel = picker.querySelector('.wiz-phone-selected');
    if (!sel) {
        sel = document.createElement('div');
        sel.className = 'wiz-phone-selected';
        picker.insertBefore(sel, picker.firstChild);
    }
    sel.innerHTML = 'Selected: <strong>' + friendly + '</strong> <button type="button" class="wiz-phone-change-btn" onclick="wizPhonePickerReset(' + qid + ')">Change</button>';
    sel.style.display = '';
    var wrap = picker.querySelector('.wiz-phone-search-wrap');
    if (wrap) { wrap.style.display = 'none'; }
    // Highlight selected in results
    picker.querySelectorAll('.wiz-phone-result-item').forEach(function(el) {
        el.classList.toggle('wiz-phone-result-selected', el.textContent.trim() === friendly);
    });
}

function wizPhonePickerReset(qid) {
    var hidden = document.getElementById('q_' + qid);
    var picker = hidden ? hidden.closest('.wiz-phone-picker') : null;
    if (!picker) { return; }
    var wrap = picker.querySelector('.wiz-phone-search-wrap');
    if (wrap) { wrap.style.display = ''; }
}

function wizSaveStep(stepEl, callback) {
    wizUploadPendingFiles(stepEl, function(ok) {
        if (!ok) { callback(false); return; }
        var answers = {};
        stepEl.querySelectorAll('.wiz-question:not(.wiz-hidden)').forEach(function(wrap) {
            var qId = parseInt(wrap.dataset.qId, 10);
            var cbAll = wrap.querySelectorAll('[name="q_' + qId + '[]"]');
            if (cbAll.length) {
                answers['q_' + qId] = Array.from(wrap.querySelectorAll('[name="q_' + qId + '[]"]:checked')).map(function(i) { return i.value; });
                return;
            }
            var radios = wrap.querySelectorAll('[name="q_' + qId + '"][type="radio"]');
            if (radios.length) {
                var chk = wrap.querySelector('[name="q_' + qId + '"]:checked');
                answers['q_' + qId] = chk ? chk.value : '';
                return;
            }
            var el = wrap.querySelector('[name="q_' + qId + '"]');
            if (el && (el.type === 'file' || el.classList.contains('wiz-file-sentinel'))) { return; }
            answers['q_' + qId] = el ? el.value : '';
        });

        fetch(_wizSaveUrl, {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({token: _wizToken, answers: answers})
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                callback(true);
            } else {
                alert(d.error || 'Failed to save progress. Please try again.');
                callback(false);
            }
        }).catch(function() {
            alert('Failed to save progress. Please check your connection and try again.');
            callback(false);
        });
    });
}

function _wizReportHeight() {
    if (window.parent !== window) {
        window.parent.postMessage({ cfwObHeight: document.body.scrollHeight }, '*');
    }
}
window.addEventListener('load', _wizReportHeight);

// Attach change listeners for condition evaluation
document.addEventListener('change', function(e) {
    if (e.target.matches('.wiz-input, [name^="q_"]')) {
        wizEvalConditions();
    }
});
document.addEventListener('input', function(e) {
    if (e.target.matches('.wiz-input')) {
        wizEvalConditions();
    }
});

// Initial evaluation
wizEvalConditions();

// ── Question Builder ────────────────────────────────────────────────────────

var _qbOptionTypes = ['select','radio','checkbox'];
var _qbFieldTypes  = [
    {v:'text',     l:'Text'},
    {v:'textarea', l:'Text Area'},
    {v:'number',   l:'Number'},
    {v:'email',    l:'Email'},
    {v:'phone',    l:'Phone'},
    {v:'select',   l:'Dropdown (Select)'},
    {v:'radio',    l:'Radio'},
    {v:'checkbox', l:'Checkbox'},
    {v:'yes_no',   l:'Yes / No'}
];

function _qbTypeOptions(selected) {
    return _qbFieldTypes.map(function(t) {
        return '<option value="' + t.v + '"' + (t.v === selected ? ' selected' : '') + '>' + t.l + '</option>';
    }).join('');
}

function _qbOptRow(val) {
    var d = document.createElement('div');
    d.className = 'wiz-qb-opt-row';
    d.innerHTML = '<input type="text" class="wiz-input wiz-qb-opt-val" placeholder="Option…" value="' + (val || '').replace(/"/g, '&quot;') + '">'
        + '<button type="button" class="wiz-qb-opt-rm" title="Remove">×</button>';
    d.querySelector('.wiz-qb-opt-rm').addEventListener('click', function() {
        d.remove();
        _qbSerializeAll();
    });
    d.querySelector('.wiz-qb-opt-val').addEventListener('input', _qbSerializeAll);
    return d;
}

function _qbAddRow(container, data) {
    data = data || {};
    var idx     = container.querySelectorAll('.wiz-qb-row').length + 1;
    var selType = data.type || 'text';
    var hasOpts = _qbOptionTypes.indexOf(selType) !== -1;

    var row = document.createElement('div');
    row.className = 'wiz-qb-row';
    row.innerHTML =
        '<div class="wiz-qb-row-header">'
        +   '<span class="wiz-qb-handle" title="Drag to reorder">&#9776;</span>'
        +   '<span class="wiz-qb-num">Q' + idx + '</span>'
        +   '<button type="button" class="wiz-qb-rm" title="Remove question">×</button>'
        + '</div>'
        + '<div class="wiz-qb-fields">'
        +   '<div class="wiz-qb-label-wrap"><label>Question / Label</label>'
        +     '<input type="text" class="wiz-input wiz-qb-qlabel" placeholder="Enter question…" value="' + (data.label || '').replace(/"/g, '&quot;') + '">'
        +   '</div>'
        +   '<div class="wiz-qb-type-wrap"><label>Input Type</label>'
        +     '<select class="wiz-input wiz-qb-qtype">' + _qbTypeOptions(selType) + '</select>'
        +   '</div>'
        + '</div>'
        + '<div class="wiz-qb-opts"' + (hasOpts ? '' : ' style="display:none"') + '>'
        +   '<div class="wiz-qb-opts-list"></div>'
        +   '<button type="button" class="wiz-qb-add-opt">+ Add option</button>'
        + '</div>';

    // Populate saved options
    var optsList = row.querySelector('.wiz-qb-opts-list');
    if (hasOpts && data.options && data.options.length) {
        data.options.forEach(function(o) { optsList.appendChild(_qbOptRow(o)); });
    }

    row.querySelector('.wiz-qb-rm').addEventListener('click', function() {
        row.remove();
        _qbRenumber(container);
        _qbSerializeAll();
    });

    row.querySelector('.wiz-qb-qlabel').addEventListener('input', _qbSerializeAll);

    row.querySelector('.wiz-qb-qtype').addEventListener('change', function() {
        var optsSection = row.querySelector('.wiz-qb-opts');
        if (_qbOptionTypes.indexOf(this.value) !== -1) {
            optsSection.style.display = '';
        } else {
            optsSection.style.display = 'none';
            optsSection.querySelector('.wiz-qb-opts-list').innerHTML = '';
        }
        _qbSerializeAll();
    });

    row.querySelector('.wiz-qb-add-opt').addEventListener('click', function() {
        optsList.appendChild(_qbOptRow(''));
        _qbSerializeAll();
    });

    // Simple drag-to-reorder
    var handle = row.querySelector('.wiz-qb-handle');
    handle.setAttribute('draggable', 'true');
    handle.addEventListener('dragstart', function(e) {
        e.dataTransfer.effectAllowed = 'move';
        container._dragRow = row;
        row.style.opacity = '0.4';
    });
    handle.addEventListener('dragend', function() {
        row.style.opacity = '';
        container._dragRow = null;
        _qbRenumber(container);
        _qbSerializeAll();
    });
    row.addEventListener('dragover', function(e) {
        if (!container._dragRow || container._dragRow === row) return;
        e.preventDefault();
        var rect = row.getBoundingClientRect();
        if (e.clientY < rect.top + rect.height / 2) {
            container.insertBefore(container._dragRow, row);
        } else {
            container.insertBefore(container._dragRow, row.nextSibling);
        }
    });

    container.insertBefore(row, container.querySelector('.wiz-qb-add-row'));
    _qbRenumber(container);
    _qbSerializeAll();
}

function _qbRenumber(container) {
    container.querySelectorAll('.wiz-qb-num').forEach(function(el, i) {
        el.textContent = 'Q' + (i + 1);
    });
}

function _qbSerialize(container) {
    var result = [];
    container.querySelectorAll('.wiz-qb-row').forEach(function(row) {
        var label = row.querySelector('.wiz-qb-qlabel').value.trim();
        var type  = row.querySelector('.wiz-qb-qtype').value;
        var opts  = [];
        if (_qbOptionTypes.indexOf(type) !== -1) {
            row.querySelectorAll('.wiz-qb-opt-val').forEach(function(inp) {
                var v = inp.value.trim();
                if (v) opts.push(v);
            });
        }
        result.push({label: label, type: type, options: opts});
    });
    return JSON.stringify(result);
}

function _qbSerializeAll() {
    document.querySelectorAll('.wiz-qb').forEach(function(container) {
        var name   = container.dataset.name;
        var hidden = document.querySelector('#' + name + ', [name="' + name + '"].wiz-qb-value');
        if (hidden) hidden.value = _qbSerialize(container);
    });
}

function _qbInit(container) {
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'wiz-qb-add-row';
    addBtn.textContent = '+ Add a question';
    addBtn.addEventListener('click', function() { _qbAddRow(container, {}); });
    container.appendChild(addBtn);

    var prefill = container.dataset.prefill;
    if (prefill) {
        try {
            var rows = JSON.parse(prefill);
            if (Array.isArray(rows) && rows.length) {
                rows.forEach(function(r) { _qbAddRow(container, r); });
                return;
            }
        } catch(e) {}
    }
    _qbAddRow(container, {});
}

// Initialize all question builders on page load
document.querySelectorAll('.wiz-qb').forEach(function(container) {
    _qbInit(container);
});

function wizSubmit() {
    var btn   = document.getElementById('wiz-submit-btn');
    var errEl = document.getElementById('wiz-submit-error');
    if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }

    btn.disabled = true;
    btn.innerHTML = 'Uploading&hellip;';

    // Upload any pending file inputs across all steps before submitting
    wizUploadPendingFiles(null, function(ok) {
        if (!ok) {
            btn.disabled = false;
            btn.innerHTML = 'Submit &#10003;';
            return;
        }

        btn.innerHTML = 'Submitting&hellip;';

        // Collect unique question IDs from all field names
        var qIds = {};
        document.querySelectorAll('[name^="q_"]').forEach(function(el) {
            var m = el.name.match(/^q_(\d+)/);
            if (m) { qIds[m[1]] = true; }
        });

        var answers = {};
        Object.keys(qIds).forEach(function(qId) {
            var cbAll = document.querySelectorAll('[name="q_' + qId + '[]"]');
            if (cbAll.length) {
                answers['q_' + qId] = Array.from(document.querySelectorAll('[name="q_' + qId + '[]"]:checked')).map(function(i) { return i.value; });
                return;
            }
            var radios = document.querySelectorAll('[name="q_' + qId + '"][type="radio"]');
            if (radios.length) {
                var chk = document.querySelector('[name="q_' + qId + '"]:checked');
                answers['q_' + qId] = chk ? chk.value : '';
                return;
            }
            var el = document.querySelector('[name="q_' + qId + '"]');
            if (el && (el.type === 'file' || el.classList.contains('wiz-file-sentinel'))) { return; }
            answers['q_' + qId] = el ? el.value : '';
        });

        fetch(_wizSubmitUrl, {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({token: _wizToken, answers: answers})
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                var container = document.querySelector('.wiz-page');
                if (container) {
                    container.innerHTML =
                        '<div style="text-align:center;padding:48px 16px;">'
                        + '<div style="font-size:40px;margin-bottom:16px;color:#e0392b;">&#10003;</div>'
                        + '<p style="color:rgba(255,255,255,.75);font-size:14px;">Thank you! Your information has been submitted. We\'ll be in touch soon.</p>'
                        + '</div>';
                }
                setTimeout(_wizReportHeight, 50);
            } else {
                btn.disabled = false;
                btn.innerHTML = 'Submit &#10003;';
                if (errEl) {
                    errEl.textContent = d.error || 'Something went wrong. Please try again.';
                    errEl.style.display = 'block';
                }
                setTimeout(_wizReportHeight, 50);
            }
        }).catch(function() {
            btn.disabled = false;
            btn.innerHTML = 'Submit &#10003;';
            if (errEl) {
                errEl.textContent = 'Request failed. Please check your connection and try again.';
                errEl.style.display = 'block';
            }
            setTimeout(_wizReportHeight, 50);
        });
    });
}
</script>
</body>
</html>
